<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Loyalty\Application\Command\AddPoints\AddPoints;
use Modules\Loyalty\Application\Command\AddPoints\AddPointsHandler;
use Modules\Loyalty\Application\Command\DeductPoints\DeductPoints;
use Modules\Loyalty\Application\Command\DeductPoints\DeductPointsHandler;
use Modules\Loyalty\Application\LoyaltyPermissions;
use Modules\Loyalty\Domain\Exception\DeductionTooLarge;
use Modules\Loyalty\Public\Dto\ExpiringPointsDto;
use Modules\Loyalty\Public\Dto\PointsByKindDto;
use Modules\Loyalty\Public\Enums\EntryKind;
use Modules\Loyalty\Public\Events\PointsChangedByHand;
use Modules\Loyalty\Public\Events\PointsEarned;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Loyalty\Support\LoyaltyFixtures as Lx;

use function Pest\Laravel\seed;

/*
| What Loyalty offers Ops (loyalty.md §2.1, §6; ops.md §1.5 C12–C14, §1.11 item 7, §2.3): `PointsEarned` and
| `PointsChangedByHand` through the outbox, ids only; one entry for its message; the points expiring in a
| range per customer; and the ledger by kind for the daily report.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    CarbonImmutable::setTestNow('2026-06-01 12:00:00 UTC');
    Lx::programme();
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/** @return list<array<string, mixed>> */
function loyaltyOpsEvents(string $type): array
{
    return array_values(DB::table('public.outbox_messages')->where('type', $type)->orderBy('id')->pluck('payload')
        ->map(static fn (string $payload): array => (array) json_decode($payload, true, flags: JSON_THROW_ON_ERROR))->all());
}

it('records PointsEarned for a delivery that earns, once, and none for one that earns nothing', function () {
    $sara = Fx::customer();
    $order = Lx::deliver($sara, 100_00);
    // Delivered again: earned once.
    Lx::deliver($sara, 100_00, orderId: $order);
    // Too little to earn a point.
    Lx::deliver($sara, 50);

    $events = loyaltyOpsEvents(PointsEarned::class);
    $entry = Lx::api()->entryForMessage((int) $events[0]['entryId']);

    expect(count($events))->toBe(1)
        ->and(array_intersect_key($events[0], array_flip(['orderId', 'storeId', 'customerId'])))->toEqual(['orderId' => $order, 'storeId' => Fx::storeId('sa'), 'customerId' => $sara])
        ->and([$entry?->kind, $entry?->points, $entry?->customerId, $entry?->balance, $entry?->reason])->toBe([EntryKind::Earned, 100, $sara, 100, null])
        ->and($entry?->orderNumber)->toBe('T-'.substr($order, -8))
        ->and($entry?->expiresAt?->format(DATE_ATOM))->toBe(CarbonImmutable::parse('2027-06-01 12:00:00 UTC')->format(DATE_ATOM));
});

it('records PointsChangedByHand for points added and taken off, with the admin\'s reason, and none for a refused one', function () {
    $sara = Fx::customer();
    Fx::actAsAdmin(['sa'], [LoyaltyPermissions::ADJUST]);
    app(AddPointsHandler::class)->handle(new AddPoints($sara, Fx::storeId('sa'), 40, 'Goodwill for a late parcel'));
    app(DeductPointsHandler::class)->handle(new DeductPoints($sara, Fx::storeId('sa'), 15, 'Added twice'));

    expect(fn () => app(DeductPointsHandler::class)->handle(new DeductPoints($sara, Fx::storeId('sa'), 500, 'Too many')))->toThrow(DeductionTooLarge::class);

    $events = loyaltyOpsEvents(PointsChangedByHand::class);
    $added = Lx::api()->entryForMessage((int) $events[0]['entryId']);
    $taken = Lx::api()->entryForMessage((int) $events[1]['entryId']);

    expect(count($events))->toBe(2)
        ->and(array_unique(array_column($events, 'customerId')))->toBe([$sara])
        ->and([$added?->kind, $added?->points, $added?->reason, $added?->expiresAt !== null])->toBe([EntryKind::Added, 40, 'Goodwill for a late parcel', true])
        ->and([$taken?->kind, $taken?->points, $taken?->reason, $taken?->expiresAt, $taken?->balance])->toBe([EntryKind::Deducted, -15, 'Added twice', null, 25])
        ->and(Lx::api()->entryForMessage(999_999_999))->toBeNull()
        ->and(Lx::api()->entryForMessage(0))->toBeNull();
});

it('gives each customer\'s points expiring in a range together, a page at a time, never a lot spent or outside it', function () {
    $customers = array_map(static fn (int $n): string => Fx::customer("c{$n}@example.test"), range(1, 3));
    sort($customers);

    foreach ($customers as $customer) {
        Lx::deliver($customer, 100_00);
    }

    // The first has a second lot expiring the same day; the third's lot expires a day later.
    CarbonImmutable::setTestNow('2026-06-01 18:00:00 UTC');
    Lx::deliver($customers[0], 30_00);
    DB::table('loyalty.lots')->join('loyalty.accounts as a', 'a.id', '=', 'loyalty.lots.account_id')
        ->where('a.customer_id', $customers[2])->update(['expires_at' => '2027-06-02 12:00:00+00']);

    $api = Lx::api();
    $sa = StoreId::fromString(Fx::storeId('sa'));
    [$from, $to] = [CarbonImmutable::parse('2027-06-01 00:00:00 UTC'), CarbonImmutable::parse('2027-06-02 00:00:00 UTC')];
    $first = $api->expiring($sa, $from, $to, null, 1);
    $second = $api->expiring($sa, $from, $to, $first->next, 1);
    $rows = static fn (array $page): array => array_map(static fn (ExpiringPointsDto $row): array => [$row->customerId, $row->points], $page);

    expect($rows($first->rows))->toBe([[$customers[0], 130]])
        ->and($rows($second->rows))->toBe([[$customers[1], 100]])
        ->and($second->next)->toBeNull()
        ->and($rows($api->expiring($sa, $from, $to->addDay())->rows))->toBe([[$customers[0], 130], [$customers[1], 100], [$customers[2], 100]])
        ->and($api->expiring($sa, $to, $from)->rows)->toBe([])
        ->and($api->expiring(StoreId::fromString(Fx::storeId('ae')), $from, $to)->rows)->toBe([])
        ->and($rows($api->expiring($sa, $from, $to, 'not-a-cursor', 5)->rows))->toBe([[$customers[0], 130], [$customers[1], 100]]);
});

it('counts the store\'s ledger by kind over a range, signed', function () {
    $sara = Fx::customer();
    Lx::deliver($sara, 100_00);
    Fx::actAsAdmin(['sa'], [LoyaltyPermissions::ADJUST]);
    app(AddPointsHandler::class)->handle(new AddPoints($sara, Fx::storeId('sa'), 40, 'Goodwill'));
    app(DeductPointsHandler::class)->handle(new DeductPoints($sara, Fx::storeId('sa'), 15, 'Mistake'));
    app(DeductPointsHandler::class)->handle(new DeductPoints($sara, Fx::storeId('sa'), 5, 'Mistake'));

    $api = Lx::api();
    $sa = StoreId::fromString(Fx::storeId('sa'));
    $today = $api->pointsByKind($sa, CarbonImmutable::now()->subHour(), CarbonImmutable::now()->addHour());

    expect(array_map(static fn (PointsByKindDto $row): array => [$row->kind, $row->entries, $row->points], $today))
        ->toBe([[EntryKind::Added, 1, 40], [EntryKind::Deducted, 2, -20], [EntryKind::Earned, 1, 100]])
        ->and($api->pointsByKind($sa, CarbonImmutable::now()->addHour(), CarbonImmutable::now()))->toBe([])
        ->and($api->pointsByKind(StoreId::fromString(Fx::storeId('ae')), CarbonImmutable::now()->subHour(), CarbonImmutable::now()->addHour()))->toBe([])
        ->and(Str::isUuid((string) (loyaltyOpsEvents(PointsEarned::class)[0]['eventId'] ?? '')))->toBeTrue();
});
