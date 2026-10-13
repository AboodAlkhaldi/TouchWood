<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Loyalty\Application\Settings\ProgrammeSettings;
use Modules\Loyalty\Domain\Exception\InvalidPointsAttribute;
use Modules\Loyalty\Domain\Exception\OrderNotSettleable;
use Modules\Loyalty\Public\Dto\EditedPointsRequest;
use Modules\Loyalty\Public\Dto\SettleEdit;
use Modules\Loyalty\Public\Dto\SettleOrder;
use Modules\Loyalty\Public\Dto\SettleReturn;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Loyalty\Support\LoyaltyFixtures as Lx;

use function Pest\Laravel\seed;

/*
| Settling cancellations, returns and staff edits through LoyaltyApi (loyalty.md §1.8, §8): written
| to the ledger inside Sales's transaction, once each, every rule of the database holding after each.
| The rules themselves are proven on the pure engine (Unit/SettlementTest); these prove the writes.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    Lx::standInForSales();
    Lx::programme();
    CarbonImmutable::setTestNow('2026-01-01 12:00:00 UTC');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

function loyaltySettleCancel(string $order): void
{
    Fx::asSystem(fn () => DB::transaction(fn () => Lx::api()->orderCancelled(new SettleOrder($order, Fx::storeId('sa'), Lx::delivering()))));
}

function loyaltySettleReturn(string $order, int $goods, int $discount, ?string $returnId = null): void
{
    $return = new SettleReturn($order, Fx::storeId('sa'), $returnId ?? Lx::orderId(), Lx::money($goods), Lx::money($discount), Lx::delivering());
    Fx::asSystem(fn () => DB::transaction(fn () => Lx::api()->orderReturned($return)));
}

function loyaltySettleBalance(string $customer): int
{
    return Lx::api()->balance($customer, Fx::storeId('sa'))->points;
}

/**
 * The owner's example: 300 expiring 1 March paid A; A earned 200, which paid B.
 *
 * @return array{string, string, string} the customer, A and B
 */
function loyaltySettleOwnersExample(): array
{
    Lx::programme(settings: [ProgrammeSettings::EXPIRY_MONTHS => 2]);
    $customer = Fx::customer();
    Lx::deliver($customer, 30_000);
    $a = Lx::place($customer, Lx::quote($customer, 300, 100_000), 100_000);
    Lx::programme(settings: [ProgrammeSettings::EXPIRY_MONTHS => 12]);
    Lx::deliver($customer, 20_000, orderId: $a);
    $b = Lx::place($customer, Lx::quote($customer, 200, 100_000), 100_000);
    CarbonImmutable::setTestNow('2026-04-10 12:00:00 UTC');

    return [$customer, $a, $b];
}

it('cancels an order: its points come back to their own lots, once — expired at once on a lot whose date passed, the history showing both', function () {
    Lx::programme(settings: [ProgrammeSettings::EXPIRY_MONTHS => 1]);
    $customer = Fx::customer();
    Lx::deliver($customer, 30_000);
    $order = Lx::place($customer, Lx::quote($customer, 300, 100_000), 100_000);
    CarbonImmutable::setTestNow('2026-03-01 12:00:00 UTC');

    loyaltySettleCancel($order);
    loyaltySettleCancel($order);

    $history = DB::table('loyalty.entries')->where('order_id', $order)->orderBy('id')->pluck('points', 'kind')->all();

    expect($history)->toBe(['REDEEMED' => -300, 'REDEMPTION_RETURNED' => 300, 'EXPIRED' => -300])
        ->and(DB::table('loyalty.orders')->where('order_id', $order)->value('cancelled_at'))->not->toBeNull()
        ->and(loyaltySettleBalance($customer))->toBe(0);
});

it('settles nothing for an order Loyalty never heard of, and refuses cancelling a delivered one', function () {
    $customer = Fx::customer();
    $delivered = Lx::deliver($customer, 10_000);

    loyaltySettleCancel(Lx::orderId());

    expect(fn () => loyaltySettleCancel($delivered))->toThrow(OrderNotSettleable::class)
        ->and(DB::table('loyalty.entries')->where('kind', 'REDEMPTION_RETURNED')->count())->toBe(0);
});

it('plays the owner\'s example on the ledger: A returned covers B\'s 200 with A\'s expired points; B cancelled later undoes it', function () {
    [$customer, $a, $b] = loyaltySettleOwnersExample();

    loyaltySettleReturn($a, 20_000, 300);

    expect(loyaltySettleBalance($customer))->toBe(0)
        ->and(DB::table('loyalty.covers')->where('order_id', $a)->get(['source', 'points'])->map(fn ($c): array => [$c->source, (int) $c->points])->all())
        ->toBe([['EXPIRED_POINTS', 200]]);

    loyaltySettleCancel($b);

    expect(loyaltySettleBalance($customer))->toBe(0)
        ->and((int) DB::table('loyalty.covers')->where('order_id', $a)->value('undone'))->toBe(200)
        ->and((int) DB::table('loyalty.accounts')->where('customer_id', $customer)->value('balance'))->toBe(0);
});

it('covers from the customer\'s other points with a cover take, and gives them back with their date when the spender is cancelled', function () {
    $customer = Fx::customer();
    $a = Lx::deliver($customer, 20_000);
    $b = Lx::place($customer, Lx::quote($customer, 200, 100_000), 100_000);
    Lx::deliver($customer, 15_000);

    loyaltySettleReturn($a, 20_000, 0);

    expect(loyaltySettleBalance($customer))->toBe(0)
        ->and(DB::table('loyalty.takes')->where('kind', 'COVER')->value('points'))->toBe(150)
        ->and((int) DB::table('loyalty.orders')->where('order_id', $a)->value('points_dropped'))->toBe(50);

    loyaltySettleCancel($b);

    expect(loyaltySettleBalance($customer))->toBe(150)
        ->and((int) DB::table('loyalty.takes')->where('kind', 'COVER')->value('back'))->toBe(150);
});

it('lands a return in three parts exactly on the order\'s points, once per return, every rule holding after each part', function () {
    $customer = Fx::customer();
    Lx::deliver($customer, 50_000);
    $order = Lx::place($customer, Lx::quote($customer, 500, 100_000), 100_000);
    Lx::deliver($customer, 99_900, orderId: $order);
    $first = Lx::orderId();

    loyaltySettleReturn($order, 33_300, 166, $first);
    loyaltySettleReturn($order, 33_300, 166, $first);
    loyaltySettleReturn($order, 33_300, 167);
    loyaltySettleReturn($order, 33_300, 167);

    $row = DB::table('loyalty.orders')->where('order_id', $order)->first() ?? throw new RuntimeException('Not recorded.');

    expect([(int) $row->points_given_back, (int) $row->points_reversed + (int) $row->points_counted_gone + (int) $row->points_covered + (int) $row->points_dropped])->toBe([500, 999])
        ->and(DB::table('loyalty.order_returns')->where('order_id', $order)->count())->toBe(3)
        ->and(loyaltySettleBalance($customer))->toBe(500);
});

it('refuses a return of an order never delivered, or past the order\'s amounts', function () {
    $customer = Fx::customer();
    Lx::deliver($customer, 30_000);
    $placed = Lx::place($customer, Lx::quote($customer, 100, 100_000), 100_000);
    $delivered = Lx::deliver($customer, 10_000);

    expect(fn () => loyaltySettleReturn($placed, 0, 50))->toThrow(OrderNotSettleable::class)
        ->and(fn () => loyaltySettleReturn(Lx::orderId(), 0, 0))->toThrow(OrderNotSettleable::class)
        ->and(fn () => loyaltySettleReturn($delivered, 10_001, 0))->toThrow(InvalidPointsAttribute::class, 'returned_amounts');
});

it('settles while the store\'s programme is off', function () {
    $customer = Fx::customer();
    Lx::deliver($customer, 30_000);
    $order = Lx::place($customer, Lx::quote($customer, 100, 100_000), 100_000);
    Lx::programme(settings: [ProgrammeSettings::ENABLED => false]);

    loyaltySettleCancel($order);

    expect(loyaltySettleBalance($customer))->toBe(300)
        ->and((int) DB::table('loyalty.accounts')->where('customer_id', $customer)->value('balance'))->toBe(300);
});

it('counts an earned lot that expired before its return as taken, and touches no other points', function () {
    Lx::programme(settings: [ProgrammeSettings::EXPIRY_MONTHS => 1]);
    $customer = Fx::customer();
    $order = Lx::deliver($customer, 20_000);
    CarbonImmutable::setTestNow('2026-03-01 12:00:00 UTC');
    Lx::deliver($customer, 50_000);

    loyaltySettleReturn($order, 20_000, 0);

    expect((int) DB::table('loyalty.orders')->where('order_id', $order)->value('points_counted_gone'))->toBe(200)
        ->and(loyaltySettleBalance($customer))->toBe(500)
        ->and((int) DB::table('loyalty.accounts')->where('customer_id', $customer)->value('balance'))->toBe(500);
});

it('gives part of an order\'s points back to the lot it spent first', function () {
    $customer = Fx::customer();
    Lx::deliver($customer, 10_000);
    CarbonImmutable::setTestNow('2026-02-01 12:00:00 UTC');
    Lx::deliver($customer, 30_000);
    $order = Lx::place($customer, Lx::quote($customer, 400, 100_000), 100_000);
    [$first, $second] = DB::table('loyalty.lots')->orderBy('id')->pluck('id')->all();

    Fx::asSystem(fn () => DB::transaction(fn () => Lx::api()->orderEdited(new SettleEdit($order, Fx::storeId('sa'), Lx::orderId(), Lx::money(300), Lx::delivering()))));

    expect([(int) DB::table('loyalty.lots')->where('id', $first)->value('points_left'), (int) DB::table('loyalty.lots')->where('id', $second)->value('points_left')])
        ->toBe([100, 0]);
});

describe('a staff edit before shipping', function () {
    it('answers what the points may still pay, then settles it to the same numbers, once per edit', function () {
        $customer = Fx::customer();
        Lx::deliver($customer, 50_000);
        $order = Lx::place($customer, Lx::quote($customer, 400, 100_000), 100_000);
        $edit = Lx::orderId();

        // Edited down to 5.00: 50% of it is 2.50.
        $after = Lx::api()->pointsAfterEdit(new EditedPointsRequest($order, Fx::storeId('sa'), Lx::money(500), Lx::money(10_000)));
        $settle = fn () => Fx::asSystem(fn () => DB::transaction(fn () => Lx::api()->orderEdited(new SettleEdit($order, Fx::storeId('sa'), $edit, $after->discount, Lx::delivering()))));
        $settled = $settle();
        $again = $settle();

        expect([$after->discount->minorUnits, $after->pointsStillCharged])->toBe([250, 250])
            ->and([$settled->discount->minorUnits, $settled->pointsStillCharged])->toBe([250, 250])
            ->and([$again->discount->minorUnits, $again->pointsStillCharged])->toBe([250, 250])
            ->and(loyaltySettleBalance($customer))->toBe(250)
            ->and(DB::table('loyalty.order_returns')->where('order_id', $order)->value('returned_discount_minor'))->toBe(150);
    });

    it('refuses a discount above the old, and an edit after delivery', function () {
        $customer = Fx::customer();
        Lx::deliver($customer, 50_000);
        $order = Lx::place($customer, Lx::quote($customer, 400, 100_000), 100_000);
        $edit = fn (int $discount) => Fx::asSystem(fn () => DB::transaction(fn () => Lx::api()->orderEdited(new SettleEdit($order, Fx::storeId('sa'), Lx::orderId(), Lx::money($discount), Lx::delivering()))));

        expect(fn () => $edit(401))->toThrow(InvalidPointsAttribute::class);

        Lx::deliver($customer, 10_000, orderId: $order);

        expect(fn () => $edit(300))->toThrow(OrderNotSettleable::class);
    });
});

it('runs its migration down and up again', function () {
    $migration = require base_path('src/Modules/Loyalty/Infrastructure/Persistence/Migrations/2026_10_13_100000_add_loyalty_settlement_rules.php');
    $rule = static fn (): bool => DB::table('pg_constraint')->where('conname', 'orders_expired_back')->exists();

    $migration->down();
    expect($rule())->toBeFalse();

    $migration->up();
    expect($rule())->toBeTrue();
});
