<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Loyalty\Application\Command\AddPoints\AddPoints;
use Modules\Loyalty\Application\Command\AddPoints\AddPointsHandler;
use Modules\Loyalty\Application\LoyaltyPermissions;
use Modules\Loyalty\Application\Query\ListRedemptions\ListRedemptions;
use Modules\Loyalty\Application\Query\ListRedemptions\ListRedemptionsHandler;
use Modules\Loyalty\Application\Query\PointsHistoryRow;
use Modules\Loyalty\Application\Query\RedemptionRow;
use Modules\Loyalty\Application\Query\ViewCustomerPoints\ViewCustomerPoints;
use Modules\Loyalty\Application\Query\ViewCustomerPoints\ViewCustomerPointsHandler;
use Modules\Loyalty\Application\Query\ViewMyPoints\ViewMyPoints;
use Modules\Loyalty\Application\Query\ViewMyPoints\ViewMyPointsHandler;
use Modules\Loyalty\Application\Settings\ProgrammeSettings;
use Modules\Loyalty\Domain\Exception\PointsAccountNotFound;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Loyalty\Support\LoyaltyFixtures as Lx;

use function Pest\Laravel\seed;

/*
| The reads behind the screens (loyalty.md §3): a customer's own points; a customer's points as staff
| read them, in the store the points belong to, with each change by hand's admin and reason; a store's
| redemptions, newest first a page at a time. None writes.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    Lx::standInForSales();
    Lx::programme();
    Lx::programme('eg');
    CarbonImmutable::setTestNow('2026-01-01 12:00:00 UTC');
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/** @return array<string, int> every ledger table's rows */
function loyaltyReadsRows(): array
{
    $rows = [];

    foreach (['accounts', 'orders', 'entries', 'lots', 'takes', 'covers', 'lot_movements', 'order_returns'] as $table) {
        $rows[$table] = DB::table("loyalty.{$table}")->count();
    }

    return $rows;
}

it('shows a customer their own points: balance, worth, live lots, the history newest first with order numbers', function () {
    $customer = Fx::customer();
    $earned = Lx::deliver($customer, 30_000);
    CarbonImmutable::setTestNow('2026-01-02 12:00:00 UTC');
    $placed = Lx::place($customer, Lx::quote($customer, 100, 100_000), 100_000);
    $number = (string) DB::table('loyalty.orders')->where('order_id', $placed)->value('order_number');
    loyaltyByHandAddAs(Fx::actAsAdmin(['sa'], [LoyaltyPermissions::ADJUST]), $customer, 50);
    $before = loyaltyReadsRows();
    Fx::actAsCustomer($customer);

    $view = app(ViewMyPointsHandler::class)->handle(new ViewMyPoints(Fx::storeId('sa')));

    expect([$view->balance, $view->worth->minorUnits, count($view->lots)])->toBe([250, 250, 2])
        ->and(array_map(static fn (PointsHistoryRow $row): array => [$row->kind->value, $row->points, $row->orderNumber], $view->history))
        ->toBe([['ADDED', 50, null], ['REDEEMED', -100, $number], ['EARNED', 300, (string) DB::table('loyalty.orders')->where('order_id', $earned)->value('order_number')]])
        // Never the admin or the reason.
        ->and(array_map(static fn (PointsHistoryRow $row): array => [$row->staffId, $row->reason], $view->history))->each->toBe([null, null])
        ->and(loyaltyReadsRows())->toBe($before);
});

it('shows a customer with no points there a balance of 0, and refuses anyone but a customer', function () {
    $customer = Fx::customer();
    Fx::actAsCustomer($customer);

    expect(app(ViewMyPointsHandler::class)->handle(new ViewMyPoints(Fx::storeId('sa')))->balance)->toBe(0);

    Fx::actAsAdmin(['sa'], [LoyaltyPermissions::VIEW]);

    expect(fn () => app(ViewMyPointsHandler::class)->handle(new ViewMyPoints(Fx::storeId('sa'))))->toThrow(Unauthorized::class);
});

it('shows staff each change by hand with its admin and reason — a Super Admin withheld from anyone else', function () {
    $customer = Fx::customer();
    loyaltyByHandAddAs(Fx::actAsAdmin(['sa'], [LoyaltyPermissions::ADJUST]), $customer, 50, 'Goodwill');
    $superAdmin = Fx::staff(superAdmin: true, firstName: 'Hidden');
    loyaltyByHandAddAs($superAdmin, $customer, 20, 'Correction');
    Fx::actAsStaff(Fx::staffWith([LoyaltyPermissions::VIEW], ['sa']));

    $view = app(ViewCustomerPointsHandler::class)->handle(new ViewCustomerPoints($customer, Fx::storeId('sa')));
    [$bySuper, $byAdmin] = $view->history;

    expect($view->balance)->toBe(70)
        ->and([$byAdmin->reason, $byAdmin->staffHidden])->toBe(['Goodwill', false])
        ->and($byAdmin->staffName)->not->toBeNull()
        ->and([$bySuper->reason, $bySuper->staffHidden])->toBe(['Correction', true])
        ->and((string) $bySuper->staffName)->not->toContain('Hidden')
        // No id either: nothing on the screen links to them.
        ->and($bySuper->staffId)->toBeNull();
});

it('reads a customer\'s points in the store they belong to: not covered is not found, no job is refused', function () {
    $customer = Fx::customer();
    Lx::deliver($customer, 20_000, 'eg');
    Fx::actAsStaff(Fx::staffWith([LoyaltyPermissions::VIEW], ['eg']));

    // A Saudi-registered customer's Egyptian points are Egypt's.
    expect(app(ViewCustomerPointsHandler::class)->handle(new ViewCustomerPoints($customer, Fx::storeId('eg')))->balance)->toBe(200)
        ->and(app(ViewCustomerPointsHandler::class)->handle(new ViewCustomerPoints(Fx::customer('none@example.test'), Fx::storeId('eg')))->balance)->toBe(0)
        ->and(fn () => app(ViewCustomerPointsHandler::class)->handle(new ViewCustomerPoints($customer, Fx::storeId('sa'))))->toThrow(PointsAccountNotFound::class)
        ->and(fn () => app(ViewCustomerPointsHandler::class)->handle(new ViewCustomerPoints(Lx::orderId(), Fx::storeId('eg'))))->toThrow(PointsAccountNotFound::class);

    Fx::actAsStaff(Fx::staffWith([LoyaltyPermissions::ADJUST], ['eg'], RoleLevel::Admin));

    expect(fn () => app(ViewCustomerPointsHandler::class)->handle(new ViewCustomerPoints($customer, Fx::storeId('eg'))))->toThrow(Unauthorized::class);
});

it('lists a store\'s redemptions newest first, a page at a time, never skipping or repeating one, and only its own', function () {
    $customer = Fx::customer();
    Lx::deliver($customer, 100_000);
    $numbers = [];

    foreach (range(1, 5) as $n) {
        CarbonImmutable::setTestNow('2026-01-0'.min($n, 4).' 12:00:00 UTC');
        $order = Lx::place($customer, Lx::quote($customer, 10, 100_000), 100_000);
        $numbers[] = (string) DB::table('loyalty.orders')->where('order_id', $order)->value('order_number');
    }

    $egypt = Fx::customer('egypt@example.test', 'eg');
    Lx::deliver($egypt, 10_000, 'eg');
    Lx::place($egypt, Lx::quote($egypt, 10, 100_000, storeCode: 'eg'), 100_000, storeCode: 'eg');
    Fx::actAsStaff(Fx::staffWith([LoyaltyPermissions::VIEW], ['sa']));
    $seen = [];
    $page = app(ListRedemptionsHandler::class)->handle(new ListRedemptions(Fx::storeId('sa'), perPage: 2));

    $before = loyaltyReadsRows();

    // At most as many pages as there can be: a cursor that never moves fails here, never hangs.
    foreach (range(1, 4) as $_) {
        $seen = [...$seen, ...array_map(static fn (RedemptionRow $row): string => $row->orderNumber, $page->rows)];

        if ($page->nextId === null) {
            break;
        }

        // The cursor given in Riyadh's time names the same moment.
        $page = app(ListRedemptionsHandler::class)->handle(new ListRedemptions(Fx::storeId('sa'), $page->nextAt?->setTimezone(new DateTimeZone('Asia/Riyadh')), $page->nextId, 2));
    }

    $first = app(ListRedemptionsHandler::class)->handle(new ListRedemptions(Fx::storeId('sa'), perPage: 1))->rows[0];

    expect($seen)->toBe(array_reverse($numbers))
        ->and([$first->points, $first->discount->minorUnits, $first->discount->currencyCode])->toBe([10, 10, 'SAR'])
        ->and($first->customerId)->toBe($customer)
        ->and(loyaltyReadsRows())->toBe($before)
        ->and(fn () => app(ListRedemptionsHandler::class)->handle(new ListRedemptions(Fx::storeId('eg'))))->toThrow(Unauthorized::class);
});

it('never counts a lot whose date has passed, even before the sweep, and writes nothing for staff either', function () {
    Lx::programme(settings: [ProgrammeSettings::EXPIRY_MONTHS => 1]);
    $customer = Fx::customer();
    Lx::deliver($customer, 10_000);
    CarbonImmutable::setTestNow('2026-01-20 12:00:00 UTC');
    Lx::deliver($customer, 20_000);
    // A second past the first lot's date: nobody has swept it.
    CarbonImmutable::setTestNow('2026-02-01 12:00:01 UTC');
    Fx::actAsStaff(Fx::staffWith([LoyaltyPermissions::VIEW], ['sa']));
    $before = loyaltyReadsRows();

    $view = app(ViewCustomerPointsHandler::class)->handle(new ViewCustomerPoints($customer, Fx::storeId('sa')));

    expect([$view->balance, count($view->lots), $view->lots[0]->pointsLeft])->toBe([200, 1, 200])
        ->and(loyaltyReadsRows())->toBe($before);
});

it('shows a Super Admin every admin\'s real name', function () {
    $customer = Fx::customer();
    $admin = Fx::actAsAdmin(['sa'], [LoyaltyPermissions::ADJUST]);
    loyaltyByHandAddAs($admin, $customer, 50);
    Fx::actAsStaff(Fx::staff(superAdmin: true, firstName: 'Reader'));

    $row = app(ViewCustomerPointsHandler::class)->handle(new ViewCustomerPoints($customer, Fx::storeId('sa')))->history[0];

    expect([$row->staffId, $row->staffHidden])->toBe([$admin, false]);
});

it('pages a customer\'s history the same way', function () {
    $customer = Fx::customer();
    Lx::deliver($customer, 100_000);

    foreach (range(1, 4) as $n) {
        Lx::place($customer, Lx::quote($customer, 10, 100_000), 100_000);
    }

    Fx::actAsCustomer($customer);
    $ids = [];
    $view = app(ViewMyPointsHandler::class)->handle(new ViewMyPoints(Fx::storeId('sa'), perPage: 2));

    foreach (range(1, 3) as $_) {
        $ids = [...$ids, ...array_map(static fn (PointsHistoryRow $row): int => $row->id, $view->history)];

        if ($view->nextId === null) {
            break;
        }

        $view = app(ViewMyPointsHandler::class)->handle(new ViewMyPoints(Fx::storeId('sa'), $view->nextAt, $view->nextId, 2));
    }

    expect($ids)->toBe(DB::table('loyalty.entries')->orderByDesc('occurred_at')->orderByDesc('id')->pluck('id')->map(fn ($id): int => (int) $id)->all());
});

function loyaltyByHandAddAs(string $staffId, string $customerId, int $points, string $reason = 'Goodwill'): void
{
    Fx::actAsStaff($staffId);
    app(AddPointsHandler::class)->handle(new AddPoints($customerId, Fx::storeId('sa'), $points, $reason));
}
