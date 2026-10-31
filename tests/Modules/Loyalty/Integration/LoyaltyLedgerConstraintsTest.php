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
        'sa' => $sa, 'eg' => $eg, 'customer' => $customer, 'other' => $other, 'staff' => $staff,
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

/**
 * A take of the other account, from a lot of its own.
 *
 * @param  array<string, int|string>  $r
 */
function loyaltyLedgerOtherTake(array $r): int
{
    $entry = loyaltyLedgerOtherEntry($r);
    $lot = DB::table('loyalty.lots')->insertGetId(['account_id' => $r['otherAccount'], 'entry_id' => $entry, 'points' => 5, 'points_left' => 5, 'expires_at' => CarbonImmutable::now()]);

    return DB::table('loyalty.takes')->insertGetId(['account_id' => $r['otherAccount'], 'lot_id' => $lot, 'entry_id' => $entry, 'kind' => 'COVER', 'points' => 5]);
}

it('takes a whole valid ledger: every table holds its row', function () {
    $r = loyaltyLedgerRows();

    foreach (['accounts' => 3, 'orders' => 2, 'entries' => 2, 'lots' => 1, 'takes' => 1, 'covers' => 1, 'lot_movements' => 2, 'order_returns' => 1] as $table => $count) {
        expect(DB::table("loyalty.{$table}")->count())->toBe($count);
    }

    // A staff edit before delivery may lower the points discount (§1.8).
    DB::table('loyalty.orders')->where('order_id', $r['orderB'])->update(['returned_discount_minor' => 50]);
    expect(DB::table('loyalty.orders')->where('order_id', $r['orderB'])->value('returned_discount_minor'))->toBe(50);

    // A deduction by hand belongs to no order; each return settles once, so two returns settle twice;
    // an expiry may name the order and return it follows from (§1.9, §1.10).
    loyaltyLedgerEntry($r, ['kind' => 'DEDUCTED', 'points' => -5, 'staff_id' => $r['staff'], 'reason' => 'Goodwill withdrawn']);
    loyaltyLedgerEntry($r, ['kind' => 'EARNING_REVERSED', 'points' => -5, 'order_id' => $r['orderA'], 'return_id' => $r['return']]);
    $secondReturn = loyaltyLedgerUlid();
    DB::table('loyalty.order_returns')->insert(['order_id' => $r['orderA'], 'account_id' => $r['account'], 'return_id' => $secondReturn, 'returned_goods_minor' => 0, 'returned_discount_minor' => 0]);
    loyaltyLedgerEntry($r, ['kind' => 'EARNING_REVERSED', 'points' => -5, 'order_id' => $r['orderA'], 'return_id' => $secondReturn]);
    loyaltyLedgerEntry($r, ['kind' => 'EXPIRED', 'points' => -5, 'order_id' => $r['orderA'], 'return_id' => $r['return']]);
    // A LOT cover names the account's own take.
    DB::table('loyalty.covers')->insert(['account_id' => $r['account'], 'lot_id' => $r['lot'], 'order_id' => $r['orderA'], 'return_id' => $r['return'], 'source' => 'LOT', 'source_take_id' => $r['take'], 'points' => 5]);

    expect(DB::table('loyalty.entries')->count())->toBe(6)
        ->and(DB::table('loyalty.covers')->count())->toBe(2);
});

it('refuses what the code would never write', function (Closure $write, string $constraint) {
    $r = loyaltyLedgerRows();

    // The constraint's name as PostgreSQL quotes it, so one named inside another cannot answer for
    // it ("lots_entry" in "lots_entry_unique"); a message without a name is matched as written.
    expect(fn () => DB::transaction(function () use ($write, $r): bool {
        $write($r);

        return true;
    }))->toThrow(QueryException::class, str_contains($constraint, ' ') ? $constraint : "\"{$constraint}\"");
})->with([
    // accounts
    'a balance below 0' => [fn (array $r) => DB::table('loyalty.accounts')->where('id', $r['account'])->update(['balance' => -1]), 'accounts_balance'],
    'a second account for one customer in one store' => [fn (array $r) => DB::table('loyalty.accounts')->insert(['id' => loyaltyLedgerUlid(), 'customer_id' => $r['customer'], 'store_id' => $r['sa'], 'balance' => 0, 'created_at' => CarbonImmutable::now(), 'updated_at' => CarbonImmutable::now()]), 'accounts_customer_store'],
    'an account id used twice' => [fn (array $r) => DB::table('loyalty.accounts')->insert(['id' => $r['account'], 'customer_id' => $r['other'], 'store_id' => $r['eg'], 'balance' => 0, 'created_at' => CarbonImmutable::now(), 'updated_at' => CarbonImmutable::now()]), 'accounts_pkey'],
    'an account of no customer' => [fn (array $r) => DB::table('loyalty.accounts')->insert(['id' => loyaltyLedgerUlid(), 'customer_id' => loyaltyLedgerUlid(), 'store_id' => $r['sa'], 'balance' => 0, 'created_at' => CarbonImmutable::now(), 'updated_at' => CarbonImmutable::now()]), 'accounts_customer'],
    'an account in no store' => [fn (array $r) => DB::table('loyalty.accounts')->insert(['id' => loyaltyLedgerUlid(), 'customer_id' => $r['other'], 'store_id' => loyaltyLedgerUlid(), 'balance' => 0, 'created_at' => CarbonImmutable::now(), 'updated_at' => CarbonImmutable::now()]), 'accounts_store'],
    // orders
    'an empty order number' => [fn (array $r) => DB::table('loyalty.orders')->where('order_id', $r['orderA'])->update(['order_number' => '']), 'orders_number'],
    'an order id used twice' => [fn (array $r) => DB::table('loyalty.orders')->insert(['order_id' => $r['orderA'], 'order_number' => 'SA-20002', 'account_id' => $r['otherAccount'], 'store_id' => $r['sa'], 'currency_code' => 'SAR']), 'orders_pkey'],
    'an order in no currency' => [fn (array $r) => DB::table('loyalty.orders')->insert(['order_id' => loyaltyLedgerUlid(), 'order_number' => 'SA-20003', 'account_id' => $r['account'], 'store_id' => $r['sa'], 'currency_code' => 'XXX']), 'orders_currency'],
    // Each count below 0 on its own. The goods total, the discount, the points redeemed and the
    // points earned cannot fall below 0 alone: each is also the bound of another rule, which breaks
    // with it (orders_returned_goods, _returned_discount, _given_back, _earned_settled).
    'points dropped below 0' => [fn (array $r) => DB::table('loyalty.orders')->where('order_id', $r['orderA'])->update(['points_dropped' => -1]), 'orders_not_negative'],
    'returned goods below 0' => [fn (array $r) => DB::table('loyalty.orders')->where('order_id', $r['orderA'])->update(['returned_goods_minor' => -1]), 'orders_not_negative'],
    'a returned discount below 0' => [fn (array $r) => DB::table('loyalty.orders')->where('order_id', $r['orderB'])->update(['returned_discount_minor' => -1]), 'orders_not_negative'],
    'points given back below 0' => [fn (array $r) => DB::table('loyalty.orders')->where('order_id', $r['orderB'])->update(['points_given_back' => -1, 'points_back_through_covers' => -1]), 'orders_not_negative'],
    'points back through covers below 0' => [fn (array $r) => DB::table('loyalty.orders')->where('order_id', $r['orderB'])->update(['points_back_through_covers' => -1]), 'orders_not_negative'],
    'points reversed below 0' => [fn (array $r) => DB::table('loyalty.orders')->where('order_id', $r['orderA'])->update(['points_reversed' => -1]), 'orders_not_negative'],
    'points counted gone below 0' => [fn (array $r) => DB::table('loyalty.orders')->where('order_id', $r['orderA'])->update(['points_counted_gone' => -1]), 'orders_not_negative'],
    'points covered below 0' => [fn (array $r) => DB::table('loyalty.orders')->where('order_id', $r['orderA'])->update(['points_covered' => -1]), 'orders_not_negative'],
    'expired points back unused below 0' => [fn (array $r) => DB::table('loyalty.orders')->where('order_id', $r['orderA'])->update(['expired_back_unused' => -1]), 'orders_not_negative'],
    'a delivered order with no goods total' => [fn (array $r) => DB::table('loyalty.orders')->where('order_id', $r['orderA'])->update(['goods_total_minor' => null]), 'orders_delivered_total'],
    'points earned before delivery' => [fn (array $r) => DB::table('loyalty.orders')->where('order_id', $r['orderB'])->update(['points_earned' => 5]), 'orders_before_delivery'],
    // Goods returned before delivery break orders_returned_goods too, which bounds them by the total.
    'a goods total before delivery' => [fn (array $r) => DB::table('loyalty.orders')->where('order_id', $r['orderB'])->update(['goods_total_minor' => 1]), 'orders_before_delivery'],
    'delivered and cancelled' => [fn (array $r) => DB::table('loyalty.orders')->where('order_id', $r['orderA'])->update(['cancelled_at' => CarbonImmutable::now()]), 'orders_delivered_or_cancelled'],
    'points redeemed with no discount' => [fn (array $r) => DB::table('loyalty.orders')->where('order_id', $r['orderB'])->update(['discount_minor' => 0]), 'orders_redeemed_discount'],
    'a discount with no points redeemed' => [fn (array $r) => DB::table('loyalty.orders')->where('order_id', $r['orderA'])->update(['discount_minor' => 1]), 'orders_redeemed_discount'],
    'a redemption with no cap recorded' => [fn (array $r) => DB::table('loyalty.orders')->where('order_id', $r['orderB'])->update(['redemption_cap_percent' => null]), 'orders_redemption_cap'],
    'a cap past 100' => [fn (array $r) => DB::table('loyalty.orders')->where('order_id', $r['orderB'])->update(['redemption_cap_percent' => 101]), 'orders_redemption_cap'],
    'a cap below 0' => [fn (array $r) => DB::table('loyalty.orders')->where('order_id', $r['orderB'])->update(['redemption_cap_percent' => -1]), 'orders_redemption_cap'],
    'a cap on an order that redeemed nothing' => [fn (array $r) => DB::table('loyalty.orders')->where('order_id', $r['orderA'])->update(['redemption_cap_percent' => 50]), 'orders_redemption_cap'],
    'returned goods past the goods total' => [fn (array $r) => DB::table('loyalty.orders')->where('order_id', $r['orderA'])->update(['returned_goods_minor' => 20_001]), 'orders_returned_goods'],
    'a returned discount past the discount' => [fn (array $r) => DB::table('loyalty.orders')->where('order_id', $r['orderB'])->update(['returned_discount_minor' => 101]), 'orders_returned_discount'],
    'more given back than redeemed' => [fn (array $r) => DB::table('loyalty.orders')->where('order_id', $r['orderB'])->update(['points_given_back' => 101]), 'orders_given_back'],
    // More back through covers than given back leaves the expired points kept below 0 as well: the
    // newer rule, first by name, answers (answer 19).
    'more back through covers than given back' => [fn (array $r) => DB::table('loyalty.orders')->where('order_id', $r['orderB'])->update(['points_back_through_covers' => 1]), 'orders_expired_back'],
    'more expired points kept than given back outside covers' => [fn (array $r) => DB::table('loyalty.orders')->where('order_id', $r['orderB'])->update(['points_given_back' => 10, 'points_back_through_covers' => 4, 'expired_back_unused' => 7]), 'orders_expired_back'],
    'an expiry naming a return but no order' => [fn (array $r) => loyaltyLedgerEntry($r, ['kind' => 'EXPIRED', 'points' => -5, 'return_id' => $r['return']]), 'entries_expired_return_order'],
    'an entry naming a return that is no settlement of its order' => [fn (array $r) => loyaltyLedgerEntry($r, ['kind' => 'EARNING_REVERSED', 'points' => -5, 'order_id' => $r['orderA'], 'return_id' => loyaltyLedgerUlid()]), 'entries_settlement'],
    'a cover naming no return' => [fn (array $r) => DB::table('loyalty.covers')->insert(['account_id' => $r['account'], 'lot_id' => $r['lot'], 'order_id' => $r['orderA'], 'source' => 'DROPPED', 'points' => 5]), 'null value in column "return_id"'],
    'more settled than earned' => [fn (array $r) => DB::table('loyalty.orders')->where('order_id', $r['orderA'])->update(['points_reversed' => 201]), 'orders_earned_settled'],
    'an order of another store\'s account' => [fn (array $r) => DB::table('loyalty.orders')->insert(['order_id' => loyaltyLedgerUlid(), 'order_number' => 'EG-1', 'account_id' => $r['account'], 'store_id' => $r['eg'], 'currency_code' => 'EGP']), 'orders_account'],
    // order_returns
    'a returned amount below 0' => [fn (array $r) => DB::table('loyalty.order_returns')->insert(['order_id' => $r['orderA'], 'account_id' => $r['account'], 'return_id' => loyaltyLedgerUlid(), 'returned_goods_minor' => -1, 'returned_discount_minor' => 0]), 'order_returns_not_negative'],
    'a returned discount amount below 0' => [fn (array $r) => DB::table('loyalty.order_returns')->insert(['order_id' => $r['orderA'], 'account_id' => $r['account'], 'return_id' => loyaltyLedgerUlid(), 'returned_goods_minor' => 0, 'returned_discount_minor' => -1]), 'order_returns_not_negative'],
    'a return settled twice' => [fn (array $r) => DB::table('loyalty.order_returns')->insert(['order_id' => $r['orderA'], 'account_id' => $r['account'], 'return_id' => $r['return'], 'returned_goods_minor' => 0, 'returned_discount_minor' => 0]), 'order_returns_pkey'],
    'a return of another account\'s order' => [fn (array $r) => DB::table('loyalty.order_returns')->insert(['order_id' => $r['orderA'], 'account_id' => $r['otherAccount'], 'return_id' => loyaltyLedgerUlid(), 'returned_goods_minor' => 0, 'returned_discount_minor' => 0]), 'order_returns_order'],
    // entries
    // An unknown kind fails every rule that lists the kinds; PostgreSQL tests a table's CHECKs in
    // the order of their names, so entries_kind is the one that answers (entries_by_hand passes).
    'an unknown kind' => [fn (array $r) => loyaltyLedgerEntry($r, ['kind' => 'BONUS', 'points' => 5]), 'entries_kind'],
    'a negative earning' => [fn (array $r) => loyaltyLedgerEntry($r, ['kind' => 'EARNED', 'points' => -5, 'order_id' => $r['orderB']]), 'entries_sign'],
    'an entry of 0 points' => [fn (array $r) => loyaltyLedgerEntry($r, ['kind' => 'ADDED', 'points' => 0, 'staff_id' => $r['staff'], 'reason' => 'Goodwill']), 'entries_sign'],
    'an earning with no order' => [fn (array $r) => loyaltyLedgerEntry($r, ['kind' => 'EARNED', 'points' => 5]), 'entries_order_for_kind'],
    'a change by hand on an order' => [fn (array $r) => loyaltyLedgerEntry($r, ['kind' => 'ADDED', 'points' => 5, 'order_id' => $r['orderA'], 'staff_id' => $r['staff'], 'reason' => 'Goodwill']), 'entries_order_for_kind'],
    'a return id on a redemption' => [fn (array $r) => loyaltyLedgerEntry($r, ['kind' => 'REDEEMED', 'points' => -5, 'order_id' => $r['orderA'], 'return_id' => loyaltyLedgerUlid()]), 'entries_return'],
    'a change by hand with no reason' => [fn (array $r) => loyaltyLedgerEntry($r, ['kind' => 'ADDED', 'points' => 5, 'staff_id' => $r['staff']]), 'entries_by_hand'],
    'a change by hand with no staff member' => [fn (array $r) => loyaltyLedgerEntry($r, ['kind' => 'ADDED', 'points' => 5, 'reason' => 'Goodwill']), 'entries_by_hand'],
    'a reason past 500 characters' => [fn (array $r) => DB::statement("INSERT INTO loyalty.entries (account_id, store_id, kind, points, staff_id, reason) VALUES (?, ?, 'ADDED', 5, ?, repeat('a', 501)::varchar)", [$r['account'], $r['sa'], $r['staff']]), 'value too long for type character varying(500)'],
    'an empty reason' => [fn (array $r) => loyaltyLedgerEntry($r, ['kind' => 'ADDED', 'points' => 5, 'staff_id' => $r['staff'], 'reason' => '']), 'entries_by_hand'],
    'a reason on an earning' => [fn (array $r) => loyaltyLedgerEntry($r, ['kind' => 'EARNED', 'points' => 5, 'order_id' => $r['orderB'], 'reason' => 'Goodwill']), 'entries_by_hand'],
    'a staff member on an earning' => [fn (array $r) => loyaltyLedgerEntry($r, ['kind' => 'EARNED', 'points' => 5, 'order_id' => $r['orderB'], 'staff_id' => $r['staff']]), 'entries_by_hand'],
    'a change by hand by no staff member there is' => [fn (array $r) => loyaltyLedgerEntry($r, ['kind' => 'ADDED', 'points' => 5, 'staff_id' => loyaltyLedgerUlid(), 'reason' => 'Goodwill']), 'entries_staff'],
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
    'a lot earned on another account\'s order' => [fn (array $r) => DB::table('loyalty.lots')->insert(['account_id' => $r['account'], 'entry_id' => $r['redeemed'], 'order_id' => loyaltyLedgerOtherOrder($r), 'points' => 5, 'points_left' => 5, 'expires_at' => CarbonImmutable::now()]), 'lots_order'],
    // takes
    'an unknown kind of take' => [fn (array $r) => DB::table('loyalty.takes')->insert(['account_id' => $r['account'], 'lot_id' => $r['lot'], 'entry_id' => $r['redeemed'], 'kind' => 'GIFT', 'points' => 5]), 'takes_kind'],
    'more back than taken' => [fn (array $r) => DB::table('loyalty.takes')->where('id', $r['take'])->update(['back' => 101]), 'takes_points'],
    'less than nothing back' => [fn (array $r) => DB::table('loyalty.takes')->where('id', $r['take'])->update(['back' => -1]), 'takes_points'],
    'a take of 0 points' => [fn (array $r) => DB::table('loyalty.takes')->insert(['account_id' => $r['account'], 'lot_id' => $r['lot'], 'entry_id' => $r['redeemed'], 'kind' => 'REDEMPTION', 'points' => 0]), 'takes_points'],
    'a take from another account\'s lot' => [fn (array $r) => DB::table('loyalty.takes')->insert(['account_id' => $r['otherAccount'], 'lot_id' => $r['lot'], 'entry_id' => loyaltyLedgerOtherEntry($r), 'kind' => 'REDEMPTION', 'points' => 5]), 'takes_lot'],
    'a take by another account\'s entry' => [fn (array $r) => DB::table('loyalty.takes')->insert(['account_id' => $r['account'], 'lot_id' => $r['lot'], 'entry_id' => loyaltyLedgerOtherEntry($r), 'kind' => 'REDEMPTION', 'points' => 5]), 'takes_entry'],
    // covers
    'an unknown source of a cover' => [fn (array $r) => DB::table('loyalty.covers')->insert(['account_id' => $r['account'], 'lot_id' => $r['lot'], 'order_id' => $r['orderA'], 'return_id' => $r['return'], 'source' => 'GIFT', 'points' => 5]), 'covers_source'],
    'a lot cover with no take' => [fn (array $r) => DB::table('loyalty.covers')->insert(['account_id' => $r['account'], 'lot_id' => $r['lot'], 'order_id' => $r['orderA'], 'return_id' => $r['return'], 'source' => 'LOT', 'points' => 5]), 'covers_take_for_lot'],
    'a dropped cover with a take' => [fn (array $r) => DB::table('loyalty.covers')->insert(['account_id' => $r['account'], 'lot_id' => $r['lot'], 'order_id' => $r['orderA'], 'return_id' => $r['return'], 'source' => 'DROPPED', 'source_take_id' => $r['take'], 'points' => 5]), 'covers_take_for_lot'],
    'a cover of 0 points' => [fn (array $r) => DB::table('loyalty.covers')->insert(['account_id' => $r['account'], 'lot_id' => $r['lot'], 'order_id' => $r['orderA'], 'return_id' => $r['return'], 'source' => 'DROPPED', 'points' => 0]), 'covers_points'],
    'more undone than covered' => [fn (array $r) => DB::table('loyalty.covers')->where('lot_id', $r['lot'])->update(['undone' => 11]), 'covers_points'],
    'less than nothing undone' => [fn (array $r) => DB::table('loyalty.covers')->where('lot_id', $r['lot'])->update(['undone' => -1]), 'covers_points'],
    'a cover on another account\'s lot' => [fn (array $r) => DB::table('loyalty.covers')->insert(['account_id' => $r['otherAccount'], 'lot_id' => $r['lot'], 'order_id' => loyaltyLedgerOtherOrder($r), 'return_id' => $r['return'], 'source' => 'DROPPED', 'points' => 5]), 'covers_lot'],
    'a cover for another account\'s order' => [fn (array $r) => DB::table('loyalty.covers')->insert(['account_id' => $r['account'], 'lot_id' => $r['lot'], 'order_id' => loyaltyLedgerOtherOrder($r), 'return_id' => $r['return'], 'source' => 'DROPPED', 'points' => 5]), 'covers_order'],
    'a lot cover by another account\'s take' => [fn (array $r) => DB::table('loyalty.covers')->insert(['account_id' => $r['account'], 'lot_id' => $r['lot'], 'order_id' => $r['orderA'], 'return_id' => $r['return'], 'source' => 'LOT', 'source_take_id' => loyaltyLedgerOtherTake($r), 'points' => 5]), 'covers_source_take'],
    // lot_movements
    'a movement of 0 points' => [fn (array $r) => DB::table('loyalty.lot_movements')->insert(['account_id' => $r['account'], 'lot_id' => $r['lot'], 'entry_id' => $r['earned'], 'points' => 0]), 'lot_movements_points'],
    'a movement on another account\'s lot' => [fn (array $r) => DB::table('loyalty.lot_movements')->insert(['account_id' => $r['otherAccount'], 'lot_id' => $r['lot'], 'entry_id' => loyaltyLedgerOtherEntry($r), 'points' => 5]), 'lot_movements_lot'],
    'a movement by another account\'s entry' => [fn (array $r) => DB::table('loyalty.lot_movements')->insert(['account_id' => $r['account'], 'lot_id' => $r['lot'], 'entry_id' => loyaltyLedgerOtherEntry($r), 'points' => 5]), 'lot_movements_entry'],
]);

it('keeps the ledger and every lot\'s history as they were written', function (Closure $write, string $refusal) {
    $r = loyaltyLedgerRows();

    expect(fn () => DB::transaction(function () use ($write, $r): bool {
        $write($r);

        return true;
    }))->toThrow(QueryException::class, $refusal);
})->with([
    'an entry changed' => [fn (array $r) => DB::table('loyalty.entries')->where('id', $r['earned'])->update(['points' => 300]), 'loyalty.entries is append-only: UPDATE is not allowed'],
    'an entry deleted' => [fn (array $r) => DB::table('loyalty.entries')->where('id', $r['earned'])->delete(), 'loyalty.entries is append-only: DELETE is not allowed'],
    // Emptying the ledger cascades into the lots' history, whose own trigger could answer first: it
    // is switched off for this one statement (the test's transaction undoes it), so the ledger's own
    // trigger is the one seen.
    'the ledger emptied' => [function (array $r) {
        DB::statement('ALTER TABLE loyalty.lot_movements DISABLE TRIGGER lot_movements_no_truncate');
        DB::statement('TRUNCATE loyalty.entries CASCADE');
    }, 'loyalty.entries is append-only: TRUNCATE is not allowed'],
    'a movement changed' => [fn (array $r) => DB::table('loyalty.lot_movements')->where('lot_id', $r['lot'])->update(['points' => 1]), 'loyalty.lot_movements is append-only: UPDATE is not allowed'],
    'a movement deleted' => [fn (array $r) => DB::table('loyalty.lot_movements')->where('lot_id', $r['lot'])->delete(), 'loyalty.lot_movements is append-only: DELETE is not allowed'],
    'the history emptied' => [fn (array $r) => DB::statement('TRUNCATE loyalty.lot_movements'), 'loyalty.lot_movements is append-only: TRUNCATE is not allowed'],
]);

it('runs the migration itself down and up again', function () {
    $migration = require base_path('src/Modules/Loyalty/Infrastructure/Persistence/Migrations/2026_10_11_100000_create_loyalty_ledger_tables.php');
    $tables = static fn (): int => DB::table('information_schema.tables')->where('table_schema', 'loyalty')->count();
    $function = static fn (): bool => DB::table('pg_proc')->where('proname', 'ledger_is_append_only')->exists();

    // The holds of stage 7 (loyalty.md §9.6) name the accounts: their own migration goes first and comes back last.
    $holds = require base_path('src/Modules/Loyalty/Infrastructure/Persistence/Migrations/2026_10_31_100000_create_redemption_holds.php');

    expect($tables())->toBe(9)->and($function())->toBeTrue();

    $holds->down();
    $migration->down();
    expect($tables())->toBe(0)->and($function())->toBeFalse();

    $migration->up();
    expect($tables())->toBe(8)->and($function())->toBeTrue();

    $holds->up();
    expect($tables())->toBe(9);
});
