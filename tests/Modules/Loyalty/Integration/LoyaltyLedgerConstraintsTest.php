<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

use function Pest\Laravel\seed;

/*
| The database's own refusals behind the ledger's code (loyalty.md §5; handoff §5.3): every named
| CHECK, key, unique index and trigger of the ledger's migration, each refusing a row written past
| the code — each row breaking that one rule only (lesson 112), a CHECK on a nullable column broken
| with the column left NULL (lessons 35, 162).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

function loyaltyLedgerUlid(): string
{
    return strtolower((string) Str::ulid());
}

/**
 * One valid row in every table: order A was delivered and earned 200 points into a lot; order B
 * redeemed 100 of them; A's lot carries a cover; A has a settled return.
 *
 * @return array<string, int|string>
 */
function loyaltyLedgerRows(): array
{
    $now = CarbonImmutable::now();
    $sa = Fx::storeId('sa');
    $eg = Fx::storeId('eg');
    $customer = Fx::customer('ledger-one@example.test');
    $other = Fx::customer('ledger-two@example.test');
    $staff = Fx::staff();
    [$account, $otherAccount, $egAccount] = [loyaltyLedgerUlid(), loyaltyLedgerUlid(), loyaltyLedgerUlid()];

    DB::table('loyalty.accounts')->insert([
        ['id' => $account, 'customer_id' => $customer, 'store_id' => $sa, 'balance' => 100, 'created_at' => $now, 'updated_at' => $now],
        ['id' => $otherAccount, 'customer_id' => $other, 'store_id' => $sa, 'balance' => 0, 'created_at' => $now, 'updated_at' => $now],
        ['id' => $egAccount, 'customer_id' => $customer, 'store_id' => $eg, 'balance' => 0, 'created_at' => $now, 'updated_at' => $now],
    ]);

    [$orderA, $orderB] = [loyaltyLedgerUlid(), loyaltyLedgerUlid()];
    DB::table('loyalty.orders')->insert([
        'order_id' => $orderA, 'order_number' => 'SA-10428', 'account_id' => $account, 'store_id' => $sa, 'currency_code' => 'SAR',
        'goods_total_minor' => 20_000, 'points_earned' => 200, 'delivered_at' => $now,
    ]);
    DB::table('loyalty.orders')->insert([
        'order_id' => $orderB, 'order_number' => 'SA-10429', 'account_id' => $account, 'store_id' => $sa, 'currency_code' => 'SAR',
        'discount_minor' => 100, 'points_redeemed' => 100, 'redemption_cap_percent' => 50,
    ]);

    $earned = DB::table('loyalty.entries')->insertGetId(['account_id' => $account, 'store_id' => $sa, 'kind' => 'EARNED', 'points' => 200, 'order_id' => $orderA]);
    $lot = DB::table('loyalty.lots')->insertGetId(['account_id' => $account, 'entry_id' => $earned, 'order_id' => $orderA, 'points' => 200, 'points_left' => 100, 'expires_at' => $now->addMonths(12)]);
    $redeemed = DB::table('loyalty.entries')->insertGetId(['account_id' => $account, 'store_id' => $sa, 'kind' => 'REDEEMED', 'points' => -100, 'order_id' => $orderB]);
    $take = DB::table('loyalty.takes')->insertGetId(['account_id' => $account, 'lot_id' => $lot, 'entry_id' => $redeemed, 'kind' => 'REDEMPTION', 'points' => 100]);
    DB::table('loyalty.lot_movements')->insert([
        ['account_id' => $account, 'lot_id' => $lot, 'entry_id' => $earned, 'points' => 200],
        ['account_id' => $account, 'lot_id' => $lot, 'entry_id' => $redeemed, 'points' => -100],
    ]);
    $return = loyaltyLedgerUlid();
    DB::table('loyalty.order_returns')->insert(['order_id' => $orderA, 'account_id' => $account, 'return_id' => $return, 'returned_goods_minor' => 0, 'returned_discount_minor' => 0]);
    DB::table('loyalty.covers')->insert(['account_id' => $account, 'lot_id' => $lot, 'order_id' => $orderA, 'return_id' => $return, 'source' => 'EXPIRED_POINTS', 'points' => 10]);

    return [
        'sa' => $sa, 'eg' => $eg, 'customer' => $customer, 'staff' => $staff,
        'account' => $account, 'otherAccount' => $otherAccount, 'egAccount' => $egAccount,
        'orderA' => $orderA, 'orderB' => $orderB, 'earned' => $earned, 'redeemed' => $redeemed,
        'lot' => $lot, 'take' => $take, 'return' => $return,
    ];
}

/**
 * An entry of the main account in its store, with the given columns.
 *
 * @param  array<string, int|string>  $r
 * @param  array<string, mixed>  $columns
 */
function loyaltyLedgerEntry(array $r, array $columns): void
{
    DB::table('loyalty.entries')->insert(['account_id' => $r['account'], 'store_id' => $r['sa'], ...$columns]);
}

/**
 * An entry of the other account, so a row pointing at it breaks only the rule under test.
 *
 * @param  array<string, int|string>  $r
 */
function loyaltyLedgerOtherEntry(array $r): int
{
    return DB::table('loyalty.entries')->insertGetId(['account_id' => $r['otherAccount'], 'store_id' => $r['sa'], 'kind' => 'ADDED', 'points' => 5, 'staff_id' => $r['staff'], 'reason' => 'Goodwill']);
}

/**
 * An order of the other account.
 *
 * @param  array<string, int|string>  $r
 */
function loyaltyLedgerOtherOrder(array $r): string
{
    $order = loyaltyLedgerUlid();
    DB::table('loyalty.orders')->insert(['order_id' => $order, 'order_number' => 'SA-20001', 'account_id' => $r['otherAccount'], 'store_id' => $r['sa'], 'currency_code' => 'SAR']);

    return $order;
}

it('takes a whole valid ledger: every table holds its row', function () {
    $r = loyaltyLedgerRows();

    foreach (['accounts' => 3, 'orders' => 2, 'entries' => 2, 'lots' => 1, 'takes' => 1, 'covers' => 1, 'lot_movements' => 2, 'order_returns' => 1] as $table => $count) {
        expect(DB::table("loyalty.{$table}")->count())->toBe($count);
    }

    // A staff edit before delivery may lower the points discount (§1.8).
    DB::table('loyalty.orders')->where('order_id', $r['orderB'])->update(['returned_discount_minor' => 50]);
    expect(DB::table('loyalty.orders')->where('order_id', $r['orderB'])->value('returned_discount_minor'))->toBe(50);
});

it('refuses what the code would never write', function (Closure $write, string $constraint) {
    $r = loyaltyLedgerRows();

    expect(fn () => DB::transaction(function () use ($write, $r): bool {
        $write($r);

        return true;
    }))->toThrow(QueryException::class, $constraint);
})->with([
    // accounts
    'a balance below 0' => [fn (array $r) => DB::table('loyalty.accounts')->where('id', $r['account'])->update(['balance' => -1]), 'accounts_balance'],
    'a second account for one customer in one store' => [fn (array $r) => DB::table('loyalty.accounts')->insert(['id' => loyaltyLedgerUlid(), 'customer_id' => $r['customer'], 'store_id' => $r['sa'], 'balance' => 0, 'created_at' => CarbonImmutable::now(), 'updated_at' => CarbonImmutable::now()]), 'accounts_customer_store'],
    // orders
    'an empty order number' => [fn (array $r) => DB::table('loyalty.orders')->where('order_id', $r['orderA'])->update(['order_number' => '']), 'orders_number'],
    'points dropped below 0' => [fn (array $r) => DB::table('loyalty.orders')->where('order_id', $r['orderA'])->update(['points_dropped' => -1]), 'orders_not_negative'],
    'a goods total below 0' => [fn (array $r) => DB::table('loyalty.orders')->where('order_id', $r['orderA'])->update(['goods_total_minor' => -1, 'points_earned' => 0]), 'orders_not_negative'],
    'a delivered order with no goods total' => [fn (array $r) => DB::table('loyalty.orders')->where('order_id', $r['orderA'])->update(['goods_total_minor' => null]), 'orders_delivered_total'],
    'points earned before delivery' => [fn (array $r) => DB::table('loyalty.orders')->where('order_id', $r['orderB'])->update(['points_earned' => 5]), 'orders_before_delivery'],
    'delivered and cancelled' => [fn (array $r) => DB::table('loyalty.orders')->where('order_id', $r['orderA'])->update(['cancelled_at' => CarbonImmutable::now()]), 'orders_delivered_or_cancelled'],
    'points redeemed with no discount' => [fn (array $r) => DB::table('loyalty.orders')->where('order_id', $r['orderB'])->update(['discount_minor' => 0]), 'orders_redeemed_discount'],
    'a redemption with no cap recorded' => [fn (array $r) => DB::table('loyalty.orders')->where('order_id', $r['orderB'])->update(['redemption_cap_percent' => null]), 'orders_redemption_cap'],
    'a cap past 100' => [fn (array $r) => DB::table('loyalty.orders')->where('order_id', $r['orderB'])->update(['redemption_cap_percent' => 101]), 'orders_redemption_cap'],
    'a cap on an order that redeemed nothing' => [fn (array $r) => DB::table('loyalty.orders')->where('order_id', $r['orderA'])->update(['redemption_cap_percent' => 50]), 'orders_redemption_cap'],
    'returned goods past the goods total' => [fn (array $r) => DB::table('loyalty.orders')->where('order_id', $r['orderA'])->update(['returned_goods_minor' => 20_001]), 'orders_returned_goods'],
    'a returned discount past the discount' => [fn (array $r) => DB::table('loyalty.orders')->where('order_id', $r['orderB'])->update(['returned_discount_minor' => 101]), 'orders_returned_discount'],
    'more given back than redeemed' => [fn (array $r) => DB::table('loyalty.orders')->where('order_id', $r['orderB'])->update(['points_given_back' => 101]), 'orders_given_back'],
    'more back through covers than given back' => [fn (array $r) => DB::table('loyalty.orders')->where('order_id', $r['orderB'])->update(['points_back_through_covers' => 1]), 'orders_given_back'],
    'more settled than earned' => [fn (array $r) => DB::table('loyalty.orders')->where('order_id', $r['orderA'])->update(['points_reversed' => 201]), 'orders_earned_settled'],
    'an order of another store\'s account' => [fn (array $r) => DB::table('loyalty.orders')->insert(['order_id' => loyaltyLedgerUlid(), 'order_number' => 'EG-1', 'account_id' => $r['account'], 'store_id' => $r['eg'], 'currency_code' => 'EGP']), 'orders_account'],
    // order_returns
    'a returned amount below 0' => [fn (array $r) => DB::table('loyalty.order_returns')->insert(['order_id' => $r['orderA'], 'account_id' => $r['account'], 'return_id' => loyaltyLedgerUlid(), 'returned_goods_minor' => -1, 'returned_discount_minor' => 0]), 'order_returns_not_negative'],
    'a return settled twice' => [fn (array $r) => DB::table('loyalty.order_returns')->insert(['order_id' => $r['orderA'], 'account_id' => $r['account'], 'return_id' => $r['return'], 'returned_goods_minor' => 0, 'returned_discount_minor' => 0]), 'order_returns_pkey'],
    'a return of another account\'s order' => [fn (array $r) => DB::table('loyalty.order_returns')->insert(['order_id' => $r['orderA'], 'account_id' => $r['otherAccount'], 'return_id' => loyaltyLedgerUlid(), 'returned_goods_minor' => 0, 'returned_discount_minor' => 0]), 'order_returns_order'],
    // entries
    'an unknown kind' => [fn (array $r) => loyaltyLedgerEntry($r, ['kind' => 'BONUS', 'points' => 5]), 'entries_kind'],
    'a negative earning' => [fn (array $r) => loyaltyLedgerEntry($r, ['kind' => 'EARNED', 'points' => -5, 'order_id' => $r['orderB']]), 'entries_sign'],
    'an entry of 0 points' => [fn (array $r) => loyaltyLedgerEntry($r, ['kind' => 'ADDED', 'points' => 0, 'staff_id' => $r['staff'], 'reason' => 'Goodwill']), 'entries_sign'],
    'an earning with no order' => [fn (array $r) => loyaltyLedgerEntry($r, ['kind' => 'EARNED', 'points' => 5]), 'entries_order_for_kind'],
    'a change by hand on an order' => [fn (array $r) => loyaltyLedgerEntry($r, ['kind' => 'ADDED', 'points' => 5, 'order_id' => $r['orderA'], 'staff_id' => $r['staff'], 'reason' => 'Goodwill']), 'entries_order_for_kind'],
    'a return id on a redemption' => [fn (array $r) => loyaltyLedgerEntry($r, ['kind' => 'REDEEMED', 'points' => -5, 'order_id' => $r['orderA'], 'return_id' => loyaltyLedgerUlid()]), 'entries_return'],
    'a change by hand with no reason' => [fn (array $r) => loyaltyLedgerEntry($r, ['kind' => 'ADDED', 'points' => 5, 'staff_id' => $r['staff']]), 'entries_by_hand'],
    'a change by hand with no staff member' => [fn (array $r) => loyaltyLedgerEntry($r, ['kind' => 'ADDED', 'points' => 5, 'reason' => 'Goodwill']), 'entries_by_hand'],
    'a reason past 500 characters' => [fn (array $r) => DB::statement("INSERT INTO loyalty.entries (account_id, store_id, kind, points, staff_id, reason) VALUES (?, ?, 'ADDED', 5, ?, repeat('a', 501)::varchar)", [$r['account'], $r['sa'], $r['staff']]), 'value too long'],
    'an empty reason' => [fn (array $r) => loyaltyLedgerEntry($r, ['kind' => 'ADDED', 'points' => 5, 'staff_id' => $r['staff'], 'reason' => '']), 'entries_by_hand'],
    'a reason on an earning' => [fn (array $r) => loyaltyLedgerEntry($r, ['kind' => 'EARNED', 'points' => 5, 'order_id' => $r['orderB'], 'reason' => 'Goodwill']), 'entries_by_hand'],
    'a second earning for one order' => [fn (array $r) => loyaltyLedgerEntry($r, ['kind' => 'EARNED', 'points' => 5, 'order_id' => $r['orderA']]), 'entries_one_earning'],
    'a second redemption for one order' => [fn (array $r) => loyaltyLedgerEntry($r, ['kind' => 'REDEEMED', 'points' => -5, 'order_id' => $r['orderB']]), 'entries_one_redemption'],
    'a cancellation settled twice' => [function (array $r) {
        loyaltyLedgerEntry($r, ['kind' => 'REDEMPTION_RETURNED', 'points' => 5, 'order_id' => $r['orderB']]);
        loyaltyLedgerEntry($r, ['kind' => 'REDEMPTION_RETURNED', 'points' => 5, 'order_id' => $r['orderB']]);
    }, 'entries_one_settlement'],
    'a return settled twice in the ledger' => [function (array $r) {
        loyaltyLedgerEntry($r, ['kind' => 'EARNING_REVERSED', 'points' => -5, 'order_id' => $r['orderA'], 'return_id' => $r['return']]);
        loyaltyLedgerEntry($r, ['kind' => 'EARNING_REVERSED', 'points' => -5, 'order_id' => $r['orderA'], 'return_id' => $r['return']]);
    }, 'entries_one_settlement'],
    'an entry of another store\'s account' => [fn (array $r) => DB::table('loyalty.entries')->insert(['account_id' => $r['account'], 'store_id' => $r['eg'], 'kind' => 'EXPIRED', 'points' => -5]), 'entries_account'],
    'an order entry of another account' => [fn (array $r) => DB::table('loyalty.entries')->insert(['account_id' => $r['otherAccount'], 'store_id' => $r['sa'], 'kind' => 'EARNED', 'points' => 5, 'order_id' => $r['orderB']]), 'entries_order'],
    // lots
    'a lot of 0 points' => [fn (array $r) => DB::table('loyalty.lots')->insert(['account_id' => $r['account'], 'entry_id' => DB::table('loyalty.entries')->insertGetId(['account_id' => $r['account'], 'store_id' => $r['sa'], 'kind' => 'ADDED', 'points' => 5, 'staff_id' => $r['staff'], 'reason' => 'Goodwill']), 'points' => 0, 'points_left' => 0, 'expires_at' => CarbonImmutable::now()]), 'lots_points'],
    'more left in a lot than it held' => [fn (array $r) => DB::table('loyalty.lots')->where('id', $r['lot'])->update(['points_left' => 201]), 'lots_points'],
    'less than nothing left in a lot' => [fn (array $r) => DB::table('loyalty.lots')->where('id', $r['lot'])->update(['points_left' => -1]), 'lots_points'],
    'a second lot from one entry' => [fn (array $r) => DB::table('loyalty.lots')->insert(['account_id' => $r['account'], 'entry_id' => $r['earned'], 'points' => 5, 'points_left' => 5, 'expires_at' => CarbonImmutable::now()]), 'lots_entry_unique'],
    'a second lot earned on one order' => [fn (array $r) => DB::table('loyalty.lots')->insert(['account_id' => $r['account'], 'entry_id' => $r['redeemed'], 'order_id' => $r['orderA'], 'points' => 5, 'points_left' => 5, 'expires_at' => CarbonImmutable::now()]), 'lots_one_per_order'],
    'a lot formed by another account\'s entry' => [fn (array $r) => DB::table('loyalty.lots')->insert(['account_id' => $r['otherAccount'], 'entry_id' => $r['redeemed'], 'points' => 5, 'points_left' => 5, 'expires_at' => CarbonImmutable::now()]), 'lots_entry'],
    // takes
    'an unknown kind of take' => [fn (array $r) => DB::table('loyalty.takes')->insert(['account_id' => $r['account'], 'lot_id' => $r['lot'], 'entry_id' => $r['redeemed'], 'kind' => 'GIFT', 'points' => 5]), 'takes_kind'],
    'more back than taken' => [fn (array $r) => DB::table('loyalty.takes')->where('id', $r['take'])->update(['back' => 101]), 'takes_points'],
    'a take of 0 points' => [fn (array $r) => DB::table('loyalty.takes')->insert(['account_id' => $r['account'], 'lot_id' => $r['lot'], 'entry_id' => $r['redeemed'], 'kind' => 'REDEMPTION', 'points' => 0]), 'takes_points'],
    'a take from another account\'s lot' => [fn (array $r) => DB::table('loyalty.takes')->insert(['account_id' => $r['otherAccount'], 'lot_id' => $r['lot'], 'entry_id' => loyaltyLedgerOtherEntry($r), 'kind' => 'REDEMPTION', 'points' => 5]), 'takes_lot'],
    // covers
    'an unknown source of a cover' => [fn (array $r) => DB::table('loyalty.covers')->insert(['account_id' => $r['account'], 'lot_id' => $r['lot'], 'order_id' => $r['orderA'], 'source' => 'GIFT', 'points' => 5]), 'covers_source'],
    'a lot cover with no take' => [fn (array $r) => DB::table('loyalty.covers')->insert(['account_id' => $r['account'], 'lot_id' => $r['lot'], 'order_id' => $r['orderA'], 'source' => 'LOT', 'points' => 5]), 'covers_take_for_lot'],
    'a dropped cover with a take' => [fn (array $r) => DB::table('loyalty.covers')->insert(['account_id' => $r['account'], 'lot_id' => $r['lot'], 'order_id' => $r['orderA'], 'source' => 'DROPPED', 'source_take_id' => $r['take'], 'points' => 5]), 'covers_take_for_lot'],
    'more undone than covered' => [fn (array $r) => DB::table('loyalty.covers')->where('lot_id', $r['lot'])->update(['undone' => 11]), 'covers_points'],
    'a cover on another account\'s lot' => [fn (array $r) => DB::table('loyalty.covers')->insert(['account_id' => $r['otherAccount'], 'lot_id' => $r['lot'], 'order_id' => loyaltyLedgerOtherOrder($r), 'source' => 'DROPPED', 'points' => 5]), 'covers_lot'],
    'a cover for another account\'s order' => [fn (array $r) => DB::table('loyalty.covers')->insert(['account_id' => $r['account'], 'lot_id' => $r['lot'], 'order_id' => loyaltyLedgerOtherOrder($r), 'source' => 'DROPPED', 'points' => 5]), 'covers_order'],
    // lot_movements
    'a movement of 0 points' => [fn (array $r) => DB::table('loyalty.lot_movements')->insert(['account_id' => $r['account'], 'lot_id' => $r['lot'], 'entry_id' => $r['earned'], 'points' => 0]), 'lot_movements_points'],
    'a movement on another account\'s lot' => [fn (array $r) => DB::table('loyalty.lot_movements')->insert(['account_id' => $r['otherAccount'], 'lot_id' => $r['lot'], 'entry_id' => loyaltyLedgerOtherEntry($r), 'points' => 5]), 'lot_movements_lot'],
]);

it('keeps the ledger and every lot\'s history as they were written', function (Closure $write, string $table) {
    $r = loyaltyLedgerRows();

    expect(fn () => DB::transaction(function () use ($write, $r): bool {
        $write($r);

        return true;
        // Emptying the ledger cascades into the lots' history, whose own trigger may be the one to answer.
    }))->toThrow(QueryException::class, $table === '' ? 'is append-only' : "loyalty.{$table} is append-only");
})->with([
    'an entry changed' => [fn (array $r) => DB::table('loyalty.entries')->where('id', $r['earned'])->update(['points' => 300]), 'entries'],
    'an entry deleted' => [fn (array $r) => DB::table('loyalty.entries')->where('id', $r['earned'])->delete(), 'entries'],
    'the ledger emptied' => [fn (array $r) => DB::statement('TRUNCATE loyalty.entries CASCADE'), ''],
    'a movement changed' => [fn (array $r) => DB::table('loyalty.lot_movements')->where('lot_id', $r['lot'])->update(['points' => 1]), 'lot_movements'],
    'a movement deleted' => [fn (array $r) => DB::table('loyalty.lot_movements')->where('lot_id', $r['lot'])->delete(), 'lot_movements'],
    'the history emptied' => [fn (array $r) => DB::statement('TRUNCATE loyalty.lot_movements'), 'lot_movements'],
]);

it('runs the migration itself down and up again', function () {
    $migration = require base_path('src/Modules/Loyalty/Infrastructure/Persistence/Migrations/2026_10_11_100000_create_loyalty_ledger_tables.php');
    $tables = static fn (): int => DB::table('information_schema.tables')->where('table_schema', 'loyalty')->count();
    $function = static fn (): bool => DB::table('pg_proc')->where('proname', 'ledger_is_append_only')->exists();

    expect($tables())->toBe(8)->and($function())->toBeTrue();

    $migration->down();
    expect($tables())->toBe(0)->and($function())->toBeFalse();

    $migration->up();
    expect($tables())->toBe(8)->and($function())->toBeTrue();
});
