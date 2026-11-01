<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sales\Application\Command\DeleteExpiredQuotes\DeleteExpiredQuotes;
use Modules\Sales\Application\Command\DeleteExpiredQuotes\DeleteExpiredQuotesHandler;
use Modules\Sales\Domain\Repository\OrderNumbers;
use Modules\Sales\Infrastructure\Eloquent\OrderNumberSequences;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Pricing\Support\PricingFixtures as Rx;

use function Pest\Laravel\seed;

/*
| The order's tables (sales.md §5): each of the four machines' rules a CHECK, so a row no command would
| write is refused by the database too; a line's shares within its total, the gift free and one an
| order; an edit and the history never changed; one number sequence a store, from 1 (answer 35).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

/**
 * A placed order's row, waiting for approval, with any columns changed.
 *
 * @param  array<string, mixed>  $with
 */
function orderRow(array $with = []): string
{
    $id = strtolower((string) Str::ulid());
    DB::table('sales.orders')->insert([
        'id' => $id, 'store_id' => Fx::storeId('sa'), 'number' => 'SA-'.random_int(1001, 999_999), 'quote_id' => strtolower((string) Str::ulid()),
        'customer_id' => Fx::customer(strtolower((string) Str::ulid()).'@example.test'), 'audience' => 'PUBLIC', 'locale' => 'en', 'currency_code' => 'SAR',
        'customer_name' => 'Sara Ali', 'customer_email' => 'sara@example.test', 'customer_phone' => '+966501234567', 'address' => json_encode(['city' => 'Riyadh']),
        'status' => 'PENDING_APPROVAL', 'shipment_status' => 'NOT_SHIPPED', 'way_to_pay' => 'STAFF_CONTACT',
        'coupon_id' => null, 'coupon_code' => null, 'coupon_discount_minor' => 0, 'points_used' => 0, 'points_discount_minor' => 0,
        'gross_subtotal_minor' => 100_00, 'net_subtotal_minor' => 100_00, 'goods_total_minor' => 100_00, 'shipping_fee_minor' => 0,
        'shipping_rule_fee_minor' => 0, 'shipping_rule_free_above_minor' => null, 'taxable_base_minor' => 100_00, 'vat_minor' => 15_00,
        'vat_rate_basis_points' => 1500, 'order_total_minor' => 115_00, 'due_minor' => 115_00, 'paid_minor' => 0, 'refunded_minor' => 0,
        'may_ship_unpaid' => false, 'placed_at' => CarbonImmutable::now(), ...$with,
    ]);

    return $id;
}

/**
 * A line of the order, with any columns changed.
 *
 * @param  array<string, mixed>  $with
 */
function orderLineRow(string $orderId, array $with = []): string
{
    ['product' => $product, 'variants' => [$variant]] = Px::ready(['60 cm']);
    $id = strtolower((string) Str::ulid());
    DB::table('sales.order_lines')->insert([
        'id' => $id, 'order_id' => $orderId, 'store_id' => Fx::storeId('sa'), 'product_id' => $product, 'variant_id' => $variant,
        'edit_id' => null, 'is_gift' => false, 'name_ar' => 'درج', 'name_en' => 'Drawer', 'variant_values' => json_encode([]), 'code' => '5000001',
        'sale_mode' => 'RETAIL', 'price_kind' => 'BASE', 'quantity' => 1, 'unit_minor' => 100_00, 'list_unit_minor' => 100_00, 'total_minor' => 100_00,
        'coupon_share_minor' => 0, 'points_share_minor' => 0, 'vat_share_minor' => 15_00, ...$with,
    ]);

    return $id;
}

it('takes an order as placed, and each machine in a state it can reach', function (array $with) {
    expect(orderRow($with))->not->toBe('');
})->with([
    'placed' => [[]],
    'approved' => [fn () => ['status' => 'APPROVED', 'decided_by' => Fx::staff(), 'decided_at' => CarbonImmutable::now()]],
    'shipped' => [fn () => ['status' => 'APPROVED', 'decided_by' => Fx::staff(), 'decided_at' => CarbonImmutable::now(), 'shipment_status' => 'SHIPPED', 'shipped_at' => CarbonImmutable::now(), 'carrier_en' => 'SMSA', 'carrier_ar' => 'SMSA', 'tracking_number' => 'T1']],
    'delivered' => [fn () => ['status' => 'COMPLETED', 'decided_by' => Fx::staff(), 'decided_at' => CarbonImmutable::now(), 'shipment_status' => 'DELIVERED', 'shipped_at' => CarbonImmutable::now(), 'delivered_at' => CarbonImmutable::now(), 'return_deadline_at' => CarbonImmutable::now()->addDays(14), 'carrier_en' => 'SMSA', 'carrier_ar' => 'SMSA']],
    'came back' => [fn () => ['status' => 'UNDELIVERED', 'decided_by' => Fx::staff(), 'decided_at' => CarbonImmutable::now(), 'shipment_status' => 'CAME_BACK', 'shipped_at' => CarbonImmutable::now(), 'came_back_at' => CarbonImmutable::now(), 'came_back_id' => strtolower((string) Str::ulid()), 'carrier_en' => 'SMSA', 'carrier_ar' => 'SMSA']],
    'cancelled by the customer' => [fn () => ['status' => 'CANCELLED', 'cancelled_at' => CarbonImmutable::now(), 'cancel_reason' => 'CHANGED_MIND']],
    'cancelled by staff after approval' => [fn () => ['status' => 'CANCELLED', 'decided_by' => Fx::staff(), 'decided_at' => CarbonImmutable::now(), 'cancelled_at' => CarbonImmutable::now(), 'cancel_reason' => 'BY_STAFF', 'cancel_other' => 'Out of stock', 'cancelled_by' => Fx::staff()]],
    'rejected' => [fn () => ['status' => 'REJECTED', 'decided_by' => Fx::staff(), 'decided_at' => CarbonImmutable::now(), 'decision_reason' => 'No stock']],
    'with a coupon and points' => [fn () => ['coupon_id' => strtolower((string) Str::ulid()), 'coupon_code' => 'SUMMER-15', 'coupon_discount_minor' => 15_00, 'points_used' => 100, 'points_discount_minor' => 1_00]],
    'a coupon an edit left at 0' => [fn () => ['coupon_id' => strtolower((string) Str::ulid()), 'coupon_code' => 'SUMMER-15', 'coupon_discount_minor' => 0]],
]);

it('refuses an order no command would write', function (array $with, string $constraint) {
    expect(fn () => orderRow($with))->toThrow(QueryException::class, $constraint);
})->with([
    // No machine's rule takes an unknown status either: whichever check PostgreSQL reads first refuses it.
    'an unknown status' => [['status' => 'LOST'], 'check constraint "orders_'],
    'an unknown locale' => [['locale' => 'fr'], 'orders_locale'],
    'decided while waiting' => [fn () => ['decided_by' => Fx::staff(), 'decided_at' => CarbonImmutable::now()], 'orders_decision'],
    'approved undecided' => [['status' => 'APPROVED'], 'orders_decision'],
    'decided by no one' => [['status' => 'APPROVED', 'decided_at' => CarbonImmutable::now()], 'orders_decided_together'],
    'rejected without a reason' => [fn () => ['status' => 'REJECTED', 'decided_by' => Fx::staff(), 'decided_at' => CarbonImmutable::now()], 'orders_rejection_reason'],
    'cancelled with no reason' => [['status' => 'CANCELLED', 'cancelled_at' => CarbonImmutable::now()], 'orders_cancelled_reason'],
    'cancelled with no time' => [['status' => 'CANCELLED', 'cancel_reason' => 'CHANGED_MIND'], 'orders_cancelled_at'],
    'a reason without cancelling' => [['cancel_reason' => 'CHANGED_MIND'], 'orders_cancelled_reason'],
    'other with no words' => [['status' => 'CANCELLED', 'cancelled_at' => CarbonImmutable::now(), 'cancel_reason' => 'OTHER'], 'orders_cancel_other'],
    'a listed reason with words' => [['status' => 'CANCELLED', 'cancelled_at' => CarbonImmutable::now(), 'cancel_reason' => 'TOO_SLOW', 'cancel_other' => 'Slow'], 'orders_cancel_other'],
    'by staff with no one' => [['status' => 'CANCELLED', 'cancelled_at' => CarbonImmutable::now(), 'cancel_reason' => 'BY_STAFF', 'cancel_other' => 'Out of stock'], 'orders_cancelled_by_staff'],
    'a customer reason by staff' => [fn () => ['status' => 'CANCELLED', 'cancelled_at' => CarbonImmutable::now(), 'cancel_reason' => 'CHANGED_MIND', 'cancelled_by' => Fx::staff()], 'orders_cancelled_by_staff'],
    'shipped while waiting' => [['shipment_status' => 'SHIPPED', 'shipped_at' => CarbonImmutable::now(), 'carrier_en' => 'SMSA', 'carrier_ar' => 'SMSA'], 'orders_shipped_approved'],
    'shipped with no time' => [fn () => ['status' => 'APPROVED', 'decided_by' => Fx::staff(), 'decided_at' => CarbonImmutable::now(), 'shipment_status' => 'SHIPPED', 'carrier_en' => 'SMSA', 'carrier_ar' => 'SMSA'], 'orders_shipped_at'],
    'shipped with no carrier' => [fn () => ['status' => 'APPROVED', 'decided_by' => Fx::staff(), 'decided_at' => CarbonImmutable::now(), 'shipment_status' => 'SHIPPED', 'shipped_at' => CarbonImmutable::now()], 'orders_carrier'],
    'a tracking number with no carrier' => [['tracking_number' => 'T1'], 'orders_tracking_carrier'],
    'delivered not completed' => [fn () => ['status' => 'APPROVED', 'decided_by' => Fx::staff(), 'decided_at' => CarbonImmutable::now(), 'shipment_status' => 'DELIVERED', 'shipped_at' => CarbonImmutable::now(), 'delivered_at' => CarbonImmutable::now(), 'carrier_en' => 'SMSA', 'carrier_ar' => 'SMSA'], 'orders_completed_delivered'],
    'completed not delivered' => [fn () => ['status' => 'COMPLETED', 'decided_by' => Fx::staff(), 'decided_at' => CarbonImmutable::now()], 'orders_completed_delivered'],
    'cancelled after shipping' => [fn () => ['status' => 'CANCELLED', 'cancelled_at' => CarbonImmutable::now(), 'cancel_reason' => 'CHANGED_MIND', 'shipment_status' => 'SHIPPED', 'shipped_at' => CarbonImmutable::now(), 'carrier_en' => 'SMSA', 'carrier_ar' => 'SMSA'], 'shipped'],
    'came back half written' => [['came_back_id' => '01m5b9eamjdr7m263kdyn1e6r7'], 'orders_came_back_together'],
    'a return deadline before delivery' => [['return_deadline_at' => CarbonImmutable::now()], 'orders_return_deadline'],
    'cancel words without cancelling' => [['cancel_other' => 'Words'], 'orders_cancel_other'],
    'cancelled by someone, not cancelled' => [fn () => ['cancelled_by' => Fx::staff()], 'orders_cancelled_by_staff'],
    'delivered with no time' => [fn () => ['status' => 'COMPLETED', 'decided_by' => Fx::staff(), 'decided_at' => CarbonImmutable::now(), 'shipment_status' => 'DELIVERED', 'shipped_at' => CarbonImmutable::now(), 'carrier_en' => 'SMSA', 'carrier_ar' => 'SMSA', 'return_deadline_at' => CarbonImmutable::now()], 'orders_delivered_at'],
    'delivered with no deadline' => [fn () => ['status' => 'COMPLETED', 'decided_by' => Fx::staff(), 'decided_at' => CarbonImmutable::now(), 'shipment_status' => 'DELIVERED', 'shipped_at' => CarbonImmutable::now(), 'delivered_at' => CarbonImmutable::now(), 'carrier_en' => 'SMSA', 'carrier_ar' => 'SMSA'], 'orders_return_deadline'],
    'a deadline before its delivery' => [fn () => ['status' => 'COMPLETED', 'decided_by' => Fx::staff(), 'decided_at' => CarbonImmutable::now(), 'shipment_status' => 'DELIVERED', 'shipped_at' => CarbonImmutable::now(), 'delivered_at' => CarbonImmutable::now(), 'return_deadline_at' => CarbonImmutable::now()->subMinute(), 'carrier_en' => 'SMSA', 'carrier_ar' => 'SMSA'], 'orders_return_deadline'],
    'came back with no time' => [fn () => ['status' => 'UNDELIVERED', 'decided_by' => Fx::staff(), 'decided_at' => CarbonImmutable::now(), 'shipment_status' => 'CAME_BACK', 'shipped_at' => CarbonImmutable::now(), 'carrier_en' => 'SMSA', 'carrier_ar' => 'SMSA'], 'orders_came_back_at'],
    'undelivered, not come back' => [fn () => ['status' => 'UNDELIVERED', 'decided_by' => Fx::staff(), 'decided_at' => CarbonImmutable::now(), 'shipment_status' => 'SHIPPED', 'shipped_at' => CarbonImmutable::now(), 'carrier_en' => 'SMSA', 'carrier_ar' => 'SMSA'], 'orders_undelivered_came_back'],
    'an unknown audience' => [['audience' => 'VIP'], 'orders_audience'],
    'an unknown shipment status' => [['shipment_status' => 'LOST'], 'check constraint "orders_'],
    'an unknown way to pay' => [['way_to_pay' => 'CASH'], 'orders_way_to_pay'],
    'an unknown cancel reason' => [['status' => 'CANCELLED', 'cancelled_at' => CarbonImmutable::now(), 'cancel_reason' => 'BORED'], 'orders_cancel_reason'],
    'a coupon code with no coupon' => [['coupon_code' => 'SUMMER-15'], 'orders_coupon_together'],
    'a coupon discount with no coupon' => [['coupon_discount_minor' => 1_00], 'orders_coupon_discount'],
    'points with no discount' => [['points_used' => 100], 'orders_points'],
    'a discount with no points' => [['points_discount_minor' => 1_00], 'orders_points'],
    'a negative amount' => [['shipping_fee_minor' => -1], 'orders_amounts'],
    'due above the total' => [['due_minor' => 115_01], 'orders_due'],
    'refunded above paid' => [['paid_minor' => 1_00, 'refunded_minor' => 1_01], 'orders_refunded'],
    'a second order from one quote' => [fn () => ['quote_id' => DB::table('sales.orders')->where('id', orderRow())->value('quote_id')], 'orders_one_per_quote'],
    'a number taken in the store' => [fn () => ['number' => DB::table('sales.orders')->where('id', orderRow())->value('number')], 'orders_number'],
]);

it('takes a line and a free gift line', function () {
    $order = orderRow();
    orderLineRow($order);
    orderLineRow($order, ['is_gift' => true, 'price_kind' => null, 'unit_minor' => 0, 'list_unit_minor' => 0, 'total_minor' => 0, 'vat_share_minor' => 0]);

    expect(DB::table('sales.order_lines')->where('order_id', $order)->count())->toBe(2);
});

it('refuses a line no command would write', function (array $with, string $constraint) {
    $order = orderRow();

    expect(fn () => orderLineRow($order, $with))->toThrow(QueryException::class, $constraint);
})->with([
    'no price kind' => [['price_kind' => null], 'order_lines_gift_unpriced'],
    'a gift with a price kind' => [['is_gift' => true, 'unit_minor' => 0, 'list_unit_minor' => 0, 'total_minor' => 0, 'vat_share_minor' => 0], 'order_lines_gift_unpriced'],
    'a gift with a price' => [['is_gift' => true, 'price_kind' => null, 'unit_minor' => 1, 'list_unit_minor' => 0, 'total_minor' => 0, 'vat_share_minor' => 0], 'order_lines_gift_free'],
    'a gift with VAT' => [['is_gift' => true, 'price_kind' => null, 'unit_minor' => 0, 'list_unit_minor' => 0, 'total_minor' => 0], 'order_lines_gift_free'],
    'a wholesale gift' => [['is_gift' => true, 'price_kind' => null, 'sale_mode' => 'WHOLESALE', 'unit_minor' => 0, 'list_unit_minor' => 0, 'total_minor' => 0, 'vat_share_minor' => 0], 'order_lines_gift_free'],
    'no pieces' => [['quantity' => 0], 'order_lines_quantity'],
    'an unknown mode' => [['sale_mode' => 'BULK'], 'order_lines_sale_mode'],
    'a price below 0' => [['unit_minor' => -1], 'order_lines_amounts'],
    'an unknown kind' => [['price_kind' => 'FREE'], 'order_lines_price_kind'],
    'discounts above the line' => [['coupon_share_minor' => 60_00, 'points_share_minor' => 40_01], 'order_lines_discount_within'],
    'a negative share' => [['vat_share_minor' => -1], 'order_lines_shares'],
    'another store' => [fn () => ['store_id' => Fx::storeId('eg')], 'order_lines_order'],
    'an edit of another order' => [fn () => ['edit_id' => orderEdit(orderRow())], 'order_lines_edit'],
]);

/** An edit of the order, as staff save one. */
function orderEdit(string $orderId): string
{
    $id = strtolower((string) Str::ulid());
    DB::table('sales.order_edits')->insert(['id' => $id, 'order_id' => $orderId, 'store_id' => Fx::storeId('sa'), 'reason' => 'Size swapped', 'by' => Fx::staff(), 'at' => CarbonImmutable::now(), 'before' => '{}', 'after' => '{}']);

    return $id;
}

it('keeps one gift line an order', function () {
    $order = orderRow();
    $gift = ['is_gift' => true, 'price_kind' => null, 'unit_minor' => 0, 'list_unit_minor' => 0, 'total_minor' => 0, 'vat_share_minor' => 0];
    orderLineRow($order, $gift);

    expect(fn () => orderLineRow($order, $gift))->toThrow(QueryException::class, 'order_lines_one_gift');
});

it('never changes an edit or the history', function (string $table, Closure $row, string $sql) {
    $order = orderRow();
    $row($order);

    expect(fn () => DB::statement(sprintf($sql, $table)))->toThrow(QueryException::class, $table.' is append-only');
})->with([
    'an edit changed' => ['sales.order_edits', fn (string $order) => orderEdit($order), "UPDATE %s SET reason = 'x'"],
    'an edit deleted' => ['sales.order_edits', fn (string $order) => orderEdit($order), 'DELETE FROM %s'],
    'the history changed' => ['sales.order_history', fn (string $order) => DB::table('sales.order_history')->insert(['order_id' => $order, 'store_id' => Fx::storeId('sa'), 'kind' => 'PLACED', 'at' => CarbonImmutable::now(), 'detail' => '{}']), "UPDATE %s SET kind = 'APPROVED'"],
    'the history emptied' => ['sales.order_history', fn (string $order) => null, 'TRUNCATE %s CASCADE'],
    'the history deleted' => ['sales.order_history', fn (string $order) => DB::table('sales.order_history')->insert(['order_id' => $order, 'store_id' => Fx::storeId('sa'), 'kind' => 'PLACED', 'at' => CarbonImmutable::now(), 'detail' => '{}']), 'DELETE FROM %s'],
    'the edits emptied' => ['sales.order_edits', fn (string $order) => orderEdit($order), 'TRUNCATE %s CASCADE'],
]);

it('refuses a history kind no command writes', function () {
    $order = orderRow();

    expect(fn () => DB::table('sales.order_history')->insert(['order_id' => $order, 'store_id' => Fx::storeId('sa'), 'kind' => 'LOST', 'at' => CarbonImmutable::now(), 'detail' => '{}']))->toThrow(QueryException::class);
});

describe('the numbers', function () {
    it('numbers each store on its own from 1, the seeded stores and one opened later', function () {
        $numbers = app(OrderNumbers::class);
        $sa = StoreId::fromString(Fx::storeId('sa'));
        $eg = StoreId::fromString(Fx::storeId('eg'));
        $kw = Rx::kuwait();

        expect([$numbers->next($sa), $numbers->next($sa), $numbers->next($eg), $numbers->next(StoreId::fromString($kw))])->toBe([1, 2, 1, 1]);
    });

    it('makes a store numbers once, and writes only a store id into the SQL', function () {
        $store = StoreId::fromString(Fx::storeId('sa'));
        $numbers = app(OrderNumbers::class);
        $numbers->next($store);
        $numbers->create($store);

        expect($numbers->next($store))->toBe(2)
            ->and(fn () => OrderNumberSequences::name('x; DROP TABLE sales.orders'))->toThrow(InvalidArgumentException::class);
    });
});

it('keeps a placed quote past its expiry, and deletes one never placed', function () {
    $placed = orderRow();
    $quoteOf = static fn (string $id): array => [
        'id' => $id, 'store_id' => Fx::storeId('sa'), 'customer_id' => DB::table('sales.orders')->where('id', $placed)->value('customer_id'),
        'address_id' => strtolower((string) Str::ulid()), 'coupon_code' => null, 'use_points' => false, 'points_asked' => null,
        'way_to_pay' => 'STAFF_CONTACT', 'result' => '{}', 'expires_at' => CarbonImmutable::now()->subHour(), 'created_at' => CarbonImmutable::now()->subHours(2),
    ];
    $kept = (string) DB::table('sales.orders')->where('id', $placed)->value('quote_id');
    $gone = strtolower((string) Str::ulid());
    DB::table('sales.quotes')->insert([$quoteOf($kept), $quoteOf($gone)]);

    $deleted = Fx::asSystem(fn () => app(DeleteExpiredQuotesHandler::class)->handle(new DeleteExpiredQuotes));

    expect($deleted)->toBe(1)
        ->and(DB::table('sales.quotes')->pluck('id')->all())->toBe([$kept]);
});

it('refuses an edit with no reason, and an edit or a tick of another store', function (Closure $write, string $constraint) {
    $order = orderRow();

    expect(fn () => $write($order))->toThrow(QueryException::class, $constraint);
})->with([
    'an edit with no reason' => [fn (string $order) => DB::table('sales.order_edits')->insert(['id' => strtolower((string) Str::ulid()), 'order_id' => $order, 'store_id' => Fx::storeId('sa'), 'reason' => '', 'by' => Fx::staff(), 'at' => CarbonImmutable::now(), 'before' => '{}', 'after' => '{}']), 'order_edits_reason'],
    'an edit in another store' => [fn (string $order) => DB::table('sales.order_edits')->insert(['id' => strtolower((string) Str::ulid()), 'order_id' => $order, 'store_id' => Fx::storeId('eg'), 'reason' => 'Swap', 'by' => Fx::staff(), 'at' => CarbonImmutable::now(), 'before' => '{}', 'after' => '{}']), 'order_edits_order'],
    'a tick in another store' => [fn (string $order) => DB::table('sales.order_ticks')->insert(['order_id' => $order, 'store_id' => Fx::storeId('eg'), 'variant_id' => Px::ready(['60 cm'])['variants'][0], 'ticked_by' => Fx::staff(), 'ticked_at' => CarbonImmutable::now()]), 'order_ticks_order'],
]);

it('ticks a size of an order once', function () {
    $order = orderRow();
    $tick = ['order_id' => $order, 'store_id' => Fx::storeId('sa'), 'variant_id' => Px::ready(['60 cm'])['variants'][0], 'ticked_by' => Fx::staff(), 'ticked_at' => CarbonImmutable::now()];
    DB::table('sales.order_ticks')->insert($tick);

    expect(fn () => DB::table('sales.order_ticks')->insert($tick))->toThrow(QueryException::class, 'order_ticks_pkey');
});

it('gives a store whose numbers went missing its numbers back, from 1', function () {
    $store = StoreId::fromString(Fx::storeId('sa'));
    DB::statement('DROP SEQUENCE '.OrderNumberSequences::name($store->value));

    expect(app(OrderNumbers::class)->next($store))->toBe(1)
        ->and(app(OrderNumbers::class)->next($store))->toBe(2);
});

it('starts again at 1 a store whose numbers began at 1001 before, unless it has orders', function () {
    $orderedIn = orderRow();
    $sa = Fx::storeId('sa');
    $eg = Fx::storeId('eg');

    foreach ([$sa, $eg] as $store) {
        DB::statement('DROP SEQUENCE '.OrderNumberSequences::name($store));
        DB::statement('CREATE SEQUENCE '.OrderNumberSequences::name($store).' START WITH 1001');
    }

    (require base_path('src/Modules/Sales/Infrastructure/Persistence/Migrations/2026_10_26_100000_restart_order_numbers_at_one.php'))->up();

    expect(DB::table('sales.orders')->where('id', $orderedIn)->value('store_id'))->toBe($sa)
        ->and([app(OrderNumbers::class)->next(StoreId::fromString($sa)), app(OrderNumbers::class)->next(StoreId::fromString($eg))])->toBe([1001, 1]);
});

it('makes every store numbers from 1 the way the migration does', function () {
    $stores = DB::table('platform.stores')->pluck('id')->map(fn ($id): string => (string) $id)->all();

    foreach ($stores as $id) {
        DB::statement('DROP SEQUENCE '.OrderNumberSequences::name($id));
    }

    OrderNumberSequences::createForStores(DB::connection());

    expect(array_map(fn (string $id): int => (int) DB::selectOne('SELECT nextval(?::regclass) AS n', [OrderNumberSequences::name($id)])->n, $stores))
        ->toBe(array_fill(0, count($stores), 1))
        ->and(count($stores))->toBe(3);
});
