<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Loyalty\Application\Command\AddPoints\AddPoints;
use Modules\Loyalty\Application\Command\AddPoints\AddPointsHandler;
use Modules\Loyalty\Application\Command\DeductPoints\DeductPoints;
use Modules\Loyalty\Application\Command\DeductPoints\DeductPointsHandler;
use Modules\Loyalty\Application\LoyaltyPermissions;
use Modules\Loyalty\Application\Settings\ProgrammeSettings;
use Modules\Loyalty\Domain\Exception\DeductionTooLarge;
use Modules\Loyalty\Domain\Exception\InvalidPointsAttribute;
use Modules\Loyalty\Domain\Exception\PointsAccountNotFound;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Loyalty\Support\LoyaltyFixtures as Lx;

use function Pest\Laravel\seed;

/*
| Changes by hand (loyalty.md §1.9, §3, §8 By hand): an admin's job only, in the points' store, with a
| reason, audited; adding forms a lot that lives the period; removing takes from the lots expiring
| first, never more than the live balance, and is final.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    CarbonImmutable::setTestNow('2026-06-01 12:00:00 UTC');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function loyaltyByHandAdd(string $customerId, int $points, string $reason = 'Goodwill for a late parcel', string $store = 'sa'): void
{
    app(AddPointsHandler::class)->handle(new AddPoints($customerId, Fx::storeId($store), $points, $reason));
}

function loyaltyByHandDeduct(string $customerId, int $points, string $reason = 'Points added twice by mistake', string $store = 'sa'): void
{
    app(DeductPointsHandler::class)->handle(new DeductPoints($customerId, Fx::storeId($store), $points, $reason));
}

function loyaltyByHandAdmin(string $store = 'sa'): string
{
    return Fx::actAsAdmin([$store], [LoyaltyPermissions::ADJUST]);
}

/**
 * @return array<string, mixed>
 */
function loyaltyByHandAudit(string $action): array
{
    $row = DB::table('platform.audit_entries')->where('action', $action)->orderByDesc('id')->first() ?? throw new RuntimeException("No {$action} in the audit log.");

    return ['subject_type' => $row->subject_type, 'subject_id' => $row->subject_id, 'store_id' => $row->store_id, 'actor_id' => $row->actor_id, 'changes' => json_decode((string) $row->changes, true)];
}

it('adds points as a lot that lives the period, by the admin, with the reason, audited — the programme off', function () {
    $customer = Fx::customer();
    $admin = loyaltyByHandAdmin();

    loyaltyByHandAdd($customer, 250, '  Goodwill for a late parcel  ');

    $account = (string) DB::table('loyalty.accounts')->where('customer_id', $customer)->value('id');
    $entry = DB::table('loyalty.entries')->where('account_id', $account)->first() ?? throw new RuntimeException('No entry.');
    $lot = DB::table('loyalty.lots')->where('account_id', $account)->first() ?? throw new RuntimeException('No lot.');

    expect([$entry->kind, (int) $entry->points, $entry->staff_id, $entry->reason, $entry->order_id])->toBe(['ADDED', 250, $admin, 'Goodwill for a late parcel', null])
        ->and([(int) $lot->points, (int) $lot->points_left, $lot->order_id])->toBe([250, 250, null])
        ->and(CarbonImmutable::parse((string) $lot->expires_at)->toIso8601String())->toBe('2027-06-01T12:00:00+00:00')
        ->and((int) DB::table('loyalty.accounts')->where('id', $account)->value('balance'))->toBe(250)
        ->and(loyaltyByHandAudit('loyalty.points.added'))->toMatchArray([
            'subject_type' => 'loyalty.account', 'subject_id' => $account, 'store_id' => Fx::storeId('sa'), 'actor_id' => $admin,
        ])
        ->and(loyaltyByHandAudit('loyalty.points.added')['changes'])->toBe([
            'points' => [null, 250], 'reason' => [null, 'Goodwill for a late parcel'],
        ]);
});

it('moves no existing date when the period changes', function () {
    $customer = Fx::customer();
    loyaltyByHandAdmin();
    loyaltyByHandAdd($customer, 100);
    Lx::programme(settings: [ProgrammeSettings::ENABLED => false, ProgrammeSettings::EXPIRY_MONTHS => 1]);

    loyaltyByHandAdd($customer, 50);

    expect(DB::table('loyalty.lots')->orderBy('id')->pluck('expires_at')->map(fn (mixed $at): string => CarbonImmutable::parse((string) $at)->toIso8601String())->all())
        ->toBe(['2027-06-01T12:00:00+00:00', '2026-07-01T12:00:00+00:00']);
});

it('removes points from the lots expiring first, for good, audited', function () {
    $customer = Fx::customer();
    $admin = loyaltyByHandAdmin();
    loyaltyByHandAdd($customer, 100);
    Lx::programme(settings: [ProgrammeSettings::ENABLED => false, ProgrammeSettings::EXPIRY_MONTHS => 1]);
    loyaltyByHandAdd($customer, 50);
    [$later, $sooner] = DB::table('loyalty.lots')->orderBy('id')->pluck('id')->all();

    loyaltyByHandDeduct($customer, 70);

    $entry = DB::table('loyalty.entries')->where('kind', 'DEDUCTED')->first() ?? throw new RuntimeException('No entry.');
    $moved = DB::table('loyalty.lot_movements')->where('entry_id', $entry->id)->orderBy('id')->get(['lot_id', 'points'])
        ->map(fn (stdClass $move): array => [(int) $move->lot_id, (int) $move->points])->all();

    expect([(int) $entry->points, $entry->staff_id, $entry->reason])->toBe([-70, $admin, 'Points added twice by mistake'])
        ->and($moved)->toBe([[(int) $sooner, -50], [(int) $later, -20]])
        ->and((int) DB::table('loyalty.lots')->where('id', $later)->value('points_left'))->toBe(80)
        ->and((int) DB::table('loyalty.accounts')->where('customer_id', $customer)->value('balance'))->toBe(80)
        // Final: no take records the points, so nothing can ever give them back.
        ->and(DB::table('loyalty.takes')->count())->toBe(0)
        ->and(loyaltyByHandAudit('loyalty.points.deducted')['changes'])->toBe([
            'points' => [null, 70], 'reason' => [null, 'Points added twice by mistake'],
        ]);
});

it('never removes more than the live balance', function () {
    $customer = Fx::customer();
    loyaltyByHandAdmin();
    loyaltyByHandAdd($customer, 100);
    Lx::programme(settings: [ProgrammeSettings::ENABLED => false, ProgrammeSettings::EXPIRY_MONTHS => 1]);
    loyaltyByHandAdd($customer, 50);
    // A day after the 50 died: the stored balance still says 150, the live balance 100.
    CarbonImmutable::setTestNow('2026-07-02 12:00:00 UTC');
    $before = DB::table('loyalty.entries')->count();

    expect(fn () => loyaltyByHandDeduct($customer, 101))->toThrow(DeductionTooLarge::class)
        ->and(DB::table('loyalty.entries')->count())->toBe($before);

    loyaltyByHandDeduct($customer, 100);

    expect((int) DB::table('loyalty.accounts')->where('customer_id', $customer)->value('balance'))->toBe(0);
});

it('refuses a change without a reason of 1 to 500 characters, or without a positive number of points', function (int $points, string $reason, string $attribute) {
    $customer = Fx::customer();
    loyaltyByHandAdmin();
    loyaltyByHandAdd($customer, 100);

    foreach ([fn () => loyaltyByHandAdd($customer, $points, $reason), fn () => loyaltyByHandDeduct($customer, $points, $reason)] as $change) {
        try {
            $change();

            throw new RuntimeException('Nothing was refused.');
        } catch (InvalidPointsAttribute $refused) {
            expect($refused->attribute)->toBe($attribute);
        }
    }

    expect(DB::table('loyalty.entries')->count())->toBe(1);
})->with([
    'no reason' => [10, '', 'reason'],
    'only spaces' => [10, '   ', 'reason'],
    'a reason past 500 characters' => [10, str_repeat('a', 501), 'reason'],
    'no points' => [0, 'Goodwill', 'points'],
    'points below 0' => [-5, 'Goodwill', 'points'],
]);

it('takes a reason of exactly 500 characters', function () {
    $customer = Fx::customer();
    loyaltyByHandAdmin();

    loyaltyByHandAdd($customer, 10, str_repeat('a', 500));

    expect(mb_strlen((string) DB::table('loyalty.entries')->value('reason')))->toBe(500);
});

it('answers a customer with no points there, or no such customer, as not found', function () {
    loyaltyByHandAdmin();

    expect(fn () => loyaltyByHandDeduct(Fx::customer(), 10))->toThrow(PointsAccountNotFound::class)
        ->and(fn () => loyaltyByHandAdd(Lx::orderId(), 10))->toThrow(PointsAccountNotFound::class)
        ->and(fn () => loyaltyByHandAdd('sara', 10))->toThrow(PointsAccountNotFound::class)
        ->and(DB::table('loyalty.accounts')->count())->toBe(0);
});

it('answers a store the admin does not cover as not found, and someone without the job plainly', function () {
    $customer = Fx::customer();
    loyaltyByHandAdmin('eg');

    expect(fn () => loyaltyByHandAdd($customer, 10))->toThrow(PointsAccountNotFound::class);

    Fx::actAsStaff(Fx::staffWith([LoyaltyPermissions::VIEW], ['sa']));

    expect(fn () => loyaltyByHandAdd($customer, 10))->toThrow(Unauthorized::class)
        ->and(DB::table('loyalty.entries')->count())->toBe(0);
});

it('takes a change by hand only from a staff member, never the system', function () {
    $customer = Fx::customer();

    expect(fn () => Fx::asSystem(fn () => loyaltyByHandAdd($customer, 10)))->toThrow(Unauthorized::class)
        ->and(DB::table('loyalty.entries')->count())->toBe(0);
});
