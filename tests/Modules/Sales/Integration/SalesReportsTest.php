<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sales\Application\Command\AcceptReturn\AcceptReturn;
use Modules\Sales\Application\Command\AcceptReturn\AcceptReturnHandler;
use Modules\Sales\Application\Command\ApproveOrder\ApproveOrder;
use Modules\Sales\Application\Command\ApproveOrder\ApproveOrderHandler;
use Modules\Sales\Application\Command\CancelOrder\CancelOrder;
use Modules\Sales\Application\Command\CancelOrder\CancelOrderHandler;
use Modules\Sales\Application\Command\CompleteReturn\CompleteReturn;
use Modules\Sales\Application\Command\CompleteReturn\CompleteReturnHandler;
use Modules\Sales\Application\Command\MarkCameBack\MarkCameBack;
use Modules\Sales\Application\Command\MarkCameBack\MarkCameBackHandler;
use Modules\Sales\Application\Command\MarkDelivered\MarkDelivered;
use Modules\Sales\Application\Command\MarkDelivered\MarkDeliveredHandler;
use Modules\Sales\Application\Command\ReceiveReturn\ReceiveReturn;
use Modules\Sales\Application\Command\ReceiveReturn\ReceiveReturnHandler;
use Modules\Sales\Application\Command\RecordPayment\RecordPayment;
use Modules\Sales\Application\Command\RecordPayment\RecordPaymentHandler;
use Modules\Sales\Application\Command\RecordRefund\RecordRefund;
use Modules\Sales\Application\Command\RecordRefund\RecordRefundHandler;
use Modules\Sales\Application\Command\RefuseReturn\RefuseReturn;
use Modules\Sales\Application\Command\RefuseReturn\RefuseReturnHandler;
use Modules\Sales\Application\Command\RejectOrder\RejectOrder;
use Modules\Sales\Application\Command\RejectOrder\RejectOrderHandler;
use Modules\Sales\Application\Command\RequestReturn\RequestReturn;
use Modules\Sales\Application\Command\RequestReturn\RequestReturnHandler;
use Modules\Sales\Application\Command\ShipOrder\ShipOrder;
use Modules\Sales\Application\Command\ShipOrder\ShipOrderHandler;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Public\Contracts\SalesReports;
use Modules\Sales\Public\Dto\MoneyByWayDto;
use Modules\Sales\Public\Dto\ProductNetSaleDto;
use Modules\Sales\Public\Dto\ProductSaleDto;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Sales\Support\CheckoutFixtures as Cx;

use function Pest\Laravel\seed;

/*
| `SalesReports` (sales.md §2.5; ops.md §1.5, §1.11, §2.3): an order and a return as Ops' messages need
| them, the daily report's orders, the money moved by day and way, and the transfers still awaited.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

/** @return array{order: string, number: string, customer: string, variants: list<string>} a placed order, and staff who may do everything to it */
function salesReportsOrder(): array
{
    $ready = Cx::ready();
    $placed = Cx::place(Cx::start($ready['address'])->id);
    Fx::actAsStaff(Fx::staffWith([
        SalesPermissions::ORDER_APPROVE, SalesPermissions::ORDER_FULFIL, SalesPermissions::PAYMENT_RECORD, SalesPermissions::RETURN_MANAGE, SalesPermissions::ORDER_CANCEL,
    ], ['sa']));

    return ['order' => $placed->id, 'number' => $placed->number, 'customer' => $ready['customer'], 'variants' => $ready['variants']];
}

function salesReportsShip(string $order): void
{
    app(ApproveOrderHandler::class)->handle(new ApproveOrder($order));
    app(RecordPaymentHandler::class)->handle(new RecordPayment($order, 'BANK_TRANSFER', 'T-1', null, '2026-10-19', (string) Str::uuid()));
    app(ShipOrderHandler::class)->handle(new ShipOrder($order, 'SMSA', 'TRK-77'));
}

function salesReports(): SalesReports
{
    return app(SalesReports::class);
}

it('gives an order as its message needs it, as sold, in the language asked', function () {
    ['order' => $order, 'number' => $number, 'customer' => $customer] = salesReportsOrder();
    salesReportsShip($order);
    $row = DB::table('sales.orders')->where('id', $order)->first();
    $line = DB::table('sales.order_lines')->where('order_id', $order)->where('is_gift', false)->orderBy('id')->first();

    $en = salesReports()->orderForMessage(strtoupper($order), 'en');
    $ar = salesReports()->orderForMessage($order, 'ar');

    expect([$en?->number, $en?->customerId, $en?->status, $en?->shipmentStatus, $en?->carrier, $en?->trackingNumber, $en?->rejectionReason])
        ->toBe([$number, $customer, 'APPROVED', 'SHIPPED', 'SMSA', 'TRK-77', null])
        ->and([$en?->total->minorUnits, $en?->vat->minorUnits, $en?->shipping->minorUnits, $en?->subtotal->minorUnits])
        ->toBe([(int) $row?->order_total_minor, (int) $row?->vat_minor, (int) $row?->shipping_fee_minor, (int) $row?->net_subtotal_minor])
        ->and(count($en->lines ?? []))->toBe(DB::table('sales.order_lines')->where('order_id', $order)->count())
        ->and($en?->lines[0]->name)->toBe((string) $line?->name_en)
        ->and($ar?->lines[0]->name)->toBe((string) $line?->name_ar)
        // Where it goes, as placed (owner, 2026-10-31: the "order taken" email shows it).
        ->and([$en?->recipientName, $en?->deliveryAddress])->toBe([(string) json_decode((string) $row?->address)->recipientName, (string) json_decode((string) $row?->address)->formatted])
        ->and($en?->deliveryAddress)->not->toBe('')
        ->and(salesReports()->orderForMessage(strtolower((string) Str::ulid()), 'en'))->toBeNull()
        ->and(salesReports()->orderForMessage('nope', 'en'))->toBeNull();
});

it('gives a rejected order staff\'s reason, and a return its order number and the store\'s words when refused', function () {
    ['order' => $rejected] = salesReportsOrder();
    app(RejectOrderHandler::class)->handle(new RejectOrder($rejected, 'Out of the delivery area'));
    ['order' => $order, 'number' => $number, 'customer' => $customer] = salesReportsOrder();
    salesReportsShip($order);
    app(MarkDeliveredHandler::class)->handle(new MarkDelivered($order));
    $line = (string) DB::table('sales.order_lines')->where('order_id', $order)->where('is_gift', false)->orderBy('id')->value('id');
    Fx::actAsCustomer($customer);
    $return = app(RequestReturnHandler::class)->handle(new RequestReturn($order, 'CHANGED_MIND', null, [['orderLineId' => $line, 'quantity' => 1]]));
    $asked = salesReports()->returnForMessage($return);
    Fx::actAsStaff(Fx::staffWith([SalesPermissions::RETURN_MANAGE], ['sa']));
    app(RefuseReturnHandler::class)->handle(new RefuseReturn($return, 'Opened and used'));
    $refused = salesReports()->returnForMessage($return);

    expect(salesReports()->orderForMessage($rejected, 'en')?->rejectionReason)->toBe('Out of the delivery area')
        ->and([$asked?->status, $asked?->staffReason, $asked?->orderNumber])->toBe(['REQUESTED', null, $number])
        ->and([$refused?->status, $refused?->staffReason, $refused?->refundDue, $refused?->customerId])->toBe(['REFUSED', 'Opened and used', null, $customer])
        ->and(salesReports()->returnForMessage(strtolower((string) Str::ulid())))->toBeNull();
});

it('counts the orders placed, cancelled, rejected and come back in a range', function () {
    ['order' => $rejected] = salesReportsOrder();
    app(RejectOrderHandler::class)->handle(new RejectOrder($rejected, 'Out of the delivery area'));
    ['order' => $back, 'variants' => $variants] = salesReportsOrder();
    salesReportsShip($back);
    app(MarkCameBackHandler::class)->handle(new MarkCameBack($back, [['variantId' => $variants[0], 'quantity' => 1]]));
    ['order' => $cancelled] = salesReportsOrder();
    app(CancelOrderHandler::class)->handle(new CancelOrder($cancelled, 'The customer called'));
    // Placed yesterday: rejected, cancelled and come back today still count today.
    DB::table('sales.orders')->update(['placed_at' => CarbonImmutable::now()->subDay()]);
    salesReportsOrder();

    $sa = StoreId::fromString(Fx::storeId('sa'));
    $today = salesReports()->counts($sa, CarbonImmutable::now()->subHours(2), CarbonImmutable::now()->addMinute());
    $none = salesReports()->counts($sa, CarbonImmutable::now(), CarbonImmutable::now()->subDay());

    expect([$today->placed, $today->counted, $today->individuals, $today->companies, $today->cancelled, $today->rejected, $today->cameBack])->toBe([1, 1, 1, 0, 1, 1, 1])
        ->and([$none->placed, $none->rejected])->toBe([0, 0]);
});

it('gives the money moved by the day it moved and by way, never a voided record, and the transfers awaited', function () {
    ['order' => $paid] = salesReportsOrder();
    salesReportsShip($paid);
    app(RecordRefundHandler::class)->handle(new RecordRefund($paid, '10.00', 'OTHER', null, 'Cash at the counter', '2026-10-19', (string) Str::uuid()));
    ['order' => $waiting] = salesReportsOrder();
    DB::table('sales.orders')->where('id', $waiting)->update(['way_to_pay' => 'BANK_TRANSFER']);
    $owed = (int) DB::table('sales.orders')->where('id', $waiting)->value('due_minor');

    $sa = StoreId::fromString(Fx::storeId('sa'));
    $day = salesReports()->moneyOn($sa, CarbonImmutable::parse('2026-10-19'), CarbonImmutable::parse('2026-10-19'));
    $later = salesReports()->moneyOn($sa, CarbonImmutable::parse('2026-10-20'), CarbonImmutable::parse('2026-10-25'));
    $rows = static fn (array $ways): array => array_map(static fn (MoneyByWayDto $way): array => [$way->way, $way->records, $way->amount->minorUnits], $ways);
    $transfers = salesReports()->transfersWaiting($sa);

    expect($rows($day->payments))->toBe([['BANK_TRANSFER', 1, (int) DB::table('sales.orders')->where('id', $paid)->value('paid_minor')]])
        ->and($rows($day->refunds))->toBe([['OTHER', 1, 10_00]])
        ->and([$later->payments, $later->refunds])->toBe([[], []])
        ->and([$transfers->orders, $transfers->owed->minorUnits, $transfers->owed->currencyCode])->toBe([1, $owed, 'SAR']);

    DB::table('sales.refunds')->update(['voided_by' => Fx::staff(), 'voided_at' => CarbonImmutable::now(), 'void_reason' => 'Recorded by mistake']);
    expect(salesReports()->moneyOn($sa, CarbonImmutable::parse('2026-10-19'), CarbonImmutable::parse('2026-10-19'))->refunds)->toBe([]);
});

it('gives the money moved day by day in one query, oldest first, the days with nothing left out, never a voided record', function () {
    ['order' => $paid] = salesReportsOrder();
    salesReportsShip($paid);
    $refund = static fn (string $amount, string $day) => app(RecordRefundHandler::class)->handle(new RecordRefund($paid, $amount, 'OTHER', null, 'Cash at the counter', $day, (string) Str::uuid()));
    $refund('10.00', '2026-10-19');
    $refund('5.00', '2026-10-21');
    // A second way the same day, and a payment voided that day: the ways in order, the voided one never.
    DB::table('sales.refunds')->insert([...(array) DB::table('sales.refunds')->where('amount_minor', 5_00)->first(), 'id' => strtolower((string) Str::ulid()), 'way' => 'BANK_TRANSFER', 'note' => null, 'amount_minor' => 3_00, 'request_id' => (string) Str::uuid()]);
    DB::table('sales.payments')->insert([...(array) DB::table('sales.payments')->where('order_id', $paid)->first(), 'id' => strtolower((string) Str::ulid()), 'happened_on' => '2026-10-21', 'voided_by' => Fx::staff(), 'voided_at' => CarbonImmutable::now(), 'void_reason' => 'Recorded by mistake', 'request_id' => (string) Str::uuid()]);

    $sa = StoreId::fromString(Fx::storeId('sa'));
    $rows = static fn (array $ways): array => array_map(static fn (MoneyByWayDto $way): array => [$way->way, $way->records, $way->amount->minorUnits], $ways);
    DB::flushQueryLog();
    DB::enableQueryLog();
    $days = salesReports()->moneyByDay($sa, CarbonImmutable::parse('2026-10-18'), CarbonImmutable::parse('2026-10-25'));
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();
    $whole = salesReports()->moneyOn($sa, CarbonImmutable::parse('2026-10-18'), CarbonImmutable::parse('2026-10-25'));

    expect($queries)->toBe(1)
        ->and(array_map(static fn ($day): array => [$day->day, $rows($day->payments), $rows($day->refunds)], $days))->toBe([
            ['2026-10-19', [['BANK_TRANSFER', 1, (int) DB::table('sales.orders')->where('id', $paid)->value('paid_minor')]], [['OTHER', 1, 10_00]]],
            ['2026-10-21', [], [['BANK_TRANSFER', 1, 3_00], ['OTHER', 1, 5_00]]],
        ])
        // The days add up to the whole range, as moneyOn reads it.
        ->and($rows($whole->refunds))->toBe([['BANK_TRANSFER', 1, 3_00], ['OTHER', 2, 15_00]])
        ->and(salesReports()->moneyByDay($sa, CarbonImmutable::parse('2026-10-22'), CarbonImmutable::parse('2026-10-25')))->toBe([]);

    DB::table('sales.refunds')->where('amount_minor', 10_00)->update(['voided_by' => Fx::staff(), 'voided_at' => CarbonImmutable::now(), 'void_reason' => 'Recorded by mistake']);
    expect($rows(salesReports()->moneyByDay($sa, CarbonImmutable::parse('2026-10-19'), CarbonImmutable::parse('2026-10-19'))[0]->refunds))->toBe([]);
});

it('gives a payment or a refund as its message needs it, and says when it was voided', function () {
    ['order' => $order, 'number' => $number, 'customer' => $customer] = salesReportsOrder();
    salesReportsShip($order);
    app(RecordRefundHandler::class)->handle(new RecordRefund($order, '10.00', 'OTHER', null, 'Cash at the counter', '2026-10-21', (string) Str::uuid()));
    [$payment, $refund] = [(string) DB::table('sales.payments')->where('order_id', $order)->value('id'), (string) DB::table('sales.refunds')->where('order_id', $order)->value('id')];
    $paid = (int) DB::table('sales.orders')->where('id', $order)->value('paid_minor');
    $read = static fn (string $id): ?array => ($one = salesReports()->moneyRecordForMessage($id)) === null ? null
        : [$one->moneyRecordId, $one->kind, $one->orderId, $one->orderNumber, $one->storeId, $one->customerId, $one->amount->minorUnits, $one->amount->currencyCode, $one->way, $one->happenedOn, $one->voided];

    expect($read(strtoupper($payment)))->toBe([$payment, 'PAYMENT', $order, $number, Fx::storeId('sa'), $customer, $paid, 'SAR', 'BANK_TRANSFER', '2026-10-19', false])
        ->and($read($refund))->toBe([$refund, 'REFUND', $order, $number, Fx::storeId('sa'), $customer, 10_00, 'SAR', 'OTHER', '2026-10-21', false])
        ->and($read(strtolower((string) Str::ulid())))->toBeNull()
        ->and($read('nope'))->toBeNull();

    DB::table('sales.payments')->where('id', $payment)->update(['voided_by' => Fx::staff(), 'voided_at' => CarbonImmutable::now(), 'void_reason' => 'Recorded by mistake']);
    expect(salesReports()->moneyRecordForMessage($payment)?->voided)->toBeTrue();
});

it('gives each product\'s sales net of its refunds, a product sold before the range but given back in it too, adding up to revenue by day', function () {
    $ask = static function (array $sold, string $line): string {
        Fx::actAsCustomer($sold['customer']);
        $return = app(RequestReturnHandler::class)->handle(new RequestReturn($sold['order'], 'DAMAGED', null, [['orderLineId' => $line, 'quantity' => 1]]));
        Fx::actAsStaff(Fx::staffWith([SalesPermissions::RETURN_MANAGE], ['sa']));

        return $return;
    };
    $a = salesReportsDelivered();
    $b = salesReportsDelivered();
    ['order' => $rejected] = salesReportsOrder();
    app(RejectOrderHandler::class)->handle(new RejectOrder($rejected, 'Out of the delivery area'));
    // A: one 100.00 piece refunded, the 50.00 one asked. B: placed two days ago, one 100.00 piece asked now.
    $done = $ask($a, $a['first']);
    app(AcceptReturnHandler::class)->handle(new AcceptReturn($done));
    app(ReceiveReturnHandler::class)->handle(new ReceiveReturn($done, [['orderLineId' => $a['first'], 'received' => 1, 'restocked' => 1]]));
    app(CompleteReturnHandler::class)->handle(new CompleteReturn($done));
    $ask($a, $a['second']);
    DB::table('sales.orders')->where('id', $b['order'])->update(['placed_at' => CarbonImmutable::now()->subDays(2)]);
    $ask($b, $b['first']);

    $product = static fn (array $sold): string => (string) DB::table('sales.order_lines')->where('id', $sold['first'])->value('product_id');
    $goods = (int) DB::table('sales.order_lines')->where('order_id', $a['order'])->where('is_gift', false)->sum(DB::raw('total_minor - coupon_share_minor - points_share_minor'));
    $expected = [[$product($a), 3, $goods, 100_00, 50_00], [$product($b), 0, 0, 0, 100_00]];
    usort($expected, static fn (array $x, array $y): int => strcmp($x[0], $y[0]));

    $sa = StoreId::fromString(Fx::storeId('sa'));
    [$from, $to] = [CarbonImmutable::now()->subHour(), CarbonImmutable::now()->addMinute()];
    $rows = static fn (array $page): array => array_map(static fn (ProductNetSaleDto $one): array => [$one->productId, $one->units, $one->revenue->minorUnits, $one->refunds->minorUnits, $one->waitingRefunds->minorUnits], $page);
    $first = salesReports()->productNetSales($sa, $from, $to, null, 1);
    $second = salesReports()->productNetSales($sa, $from, $to, $first->next, 1);
    $all = salesReports()->productNetSales($sa, $from, $to, 'not-a-cursor', 5)->rows;
    $days = salesReports()->revenueByDay($sa, $from, $to);
    $sum = static fn (array $list, callable $of): int => array_sum(array_map($of, $list));

    expect($rows($first->rows))->toBe([$expected[0]])
        ->and($first->next)->toBe($expected[0][0])
        ->and($rows($second->rows))->toBe([$expected[1]])
        ->and($second->next)->toBeNull()
        ->and($rows($all))->toBe($expected)
        // The products add up to revenue by day: the same refunds, counted the same way.
        ->and($sum($all, static fn (ProductNetSaleDto $one): int => $one->revenue->minorUnits))->toBe($sum($days, static fn ($day): int => $day->revenue->minorUnits))
        ->and($sum($all, static fn (ProductNetSaleDto $one): int => $one->refunds->minorUnits))->toBe($sum($days, static fn ($day): int => $day->refunds->minorUnits))
        ->and($sum($all, static fn (ProductNetSaleDto $one): int => $one->waitingRefunds->minorUnits))->toBe($sum($days, static fn ($day): int => $day->waitingRefunds->minorUnits))
        ->and(salesReports()->productNetSales($sa, $to, $from)->rows)->toBe([])
        ->and(salesReports()->productNetSales(StoreId::fromString(Fx::storeId('ae')), $from, $to)->rows)->toBe([])
        ->and(fn () => salesReports()->productNetSales(StoreId::fromString(strtolower((string) Str::ulid())), $from, $to))->toThrow(InvalidArgumentException::class);
});

it('splits one return holding two products between them, a share that does not divide evenly rounded as the refund rounds it', function () {
    $sold = salesReportsDelivered();
    // The 50.00 line made another product's (one of a rejected order, so it sells nothing here), and a coupon
    // share of 0.33 on the 100.00 line (2 pieces): one piece of it moves floor((200.00 - 0.33) / 2) = 99.83.
    ['order' => $rejected] = salesReportsOrder();
    app(RejectOrderHandler::class)->handle(new RejectOrder($rejected, 'Out of the delivery area'));
    $other = (string) DB::table('sales.order_lines')->where('order_id', $rejected)->value('product_id');
    DB::table('sales.order_lines')->where('id', $sold['second'])->update(['product_id' => $other]);
    DB::table('sales.order_lines')->where('id', $sold['first'])->update(['coupon_share_minor' => 33]);
    $mine = (string) DB::table('sales.order_lines')->where('id', $sold['first'])->value('product_id');
    Fx::actAsCustomer($sold['customer']);
    app(RequestReturnHandler::class)->handle(new RequestReturn($sold['order'], 'DAMAGED', null, [
        ['orderLineId' => $sold['first'], 'quantity' => 1], ['orderLineId' => $sold['second'], 'quantity' => 1],
    ]));

    $sa = StoreId::fromString(Fx::storeId('sa'));
    [$from, $to] = [CarbonImmutable::now()->subHour(), CarbonImmutable::now()->addMinute()];
    $rows = array_map(static fn (ProductNetSaleDto $one): array => [$one->productId, $one->units, $one->revenue->minorUnits, $one->waitingRefunds->minorUnits],
        salesReports()->productNetSales($sa, $from, $to)->rows);
    $expected = [[$mine, 2, 199_67, 99_83], [$other, 1, 50_00, 50_00]];
    usort($expected, static fn (array $x, array $y): int => strcmp($x[0], $y[0]));

    expect($rows)->toBe($expected)
        ->and(salesReports()->revenueByDay($sa, $from, $to)[0]->waitingRefunds->minorUnits ?? null)->toBe(99_83 + 50_00);
});

/** @return array{order: string, number: string, customer: string, first: string, second: string, variants: list<string>} an order delivered: 2 of 100.00 and 1 of 50.00 */
function salesReportsDelivered(): array
{
    $sold = salesReportsOrder();
    salesReportsShip($sold['order']);
    app(MarkDeliveredHandler::class)->handle(new MarkDelivered($sold['order']));
    $line = static fn (int $quantity): string => (string) DB::table('sales.order_lines')->where('order_id', $sold['order'])->where('is_gift', false)->where('quantity', $quantity)->value('id');

    return [...$sold, 'first' => $line(2), 'second' => $line(1)];
}

it('gives revenue by the store\'s day: the orders that count, the refunds of returns asked that day and their VAT, the waiting ones estimated', function () {
    ['order' => $order, 'number' => $number, 'customer' => $customer, 'first' => $first, 'second' => $second] = salesReportsDelivered();
    ['order' => $rejected] = salesReportsOrder();
    app(RejectOrderHandler::class)->handle(new RejectOrder($rejected, 'Out of the delivery area'));
    $ask = static function (string $line) use ($order, $customer): string {
        Fx::actAsCustomer($customer);
        $return = app(RequestReturnHandler::class)->handle(new RequestReturn($order, 'DAMAGED', null, [['orderLineId' => $line, 'quantity' => 1]]));
        Fx::actAsStaff(Fx::staffWith([SalesPermissions::RETURN_MANAGE], ['sa']));

        return $return;
    };
    // One piece of the 100.00 size back and refunded; one of the 50.00 asked, not yet decided.
    $done = $ask($first);
    app(AcceptReturnHandler::class)->handle(new AcceptReturn($done));
    app(ReceiveReturnHandler::class)->handle(new ReceiveReturn($done, [['orderLineId' => $first, 'received' => 1, 'restocked' => 1]]));
    app(CompleteReturnHandler::class)->handle(new CompleteReturn($done));
    $open = $ask($second);

    $row = DB::table('sales.orders')->where('id', $order)->first();
    // The refund recorded on completing it, less its goods: the goods' VAT (no shipping: not every piece is back).
    $doneVat = (int) DB::table('sales.returns')->where('id', $done)->value('refund_due_minor') - 100_00;
    $openLine = DB::table('sales.order_lines')->where('id', $second)->first(['quantity', 'vat_share_minor']);
    $openVat = intdiv((int) $openLine?->vat_share_minor, (int) $openLine?->quantity);
    $sa = StoreId::fromString(Fx::storeId('sa'));
    $days = salesReports()->revenueByDay($sa, CarbonImmutable::now()->subHours(2), CarbonImmutable::now()->addMinute());

    expect(count($days))->toBe(1)
        ->and($days[0]->day)->toBe(CarbonImmutable::now('Asia/Riyadh')->format('Y-m-d'))
        ->and([$days[0]->orders, $days[0]->revenue->minorUnits, $days[0]->vat->minorUnits, $days[0]->shipping->minorUnits])
        ->toBe([1, (int) $row?->goods_total_minor, (int) $row?->vat_minor, (int) $row?->shipping_fee_minor])
        ->and([$days[0]->refunds->minorUnits, $days[0]->waitingRefunds->minorUnits])->toBe([100_00, 50_00])
        ->and(array_map(static fn ($one): array => [$one->returnId, $one->orderNumber, $one->estimate->minorUnits], $days[0]->waiting))->toBe([[$open, $number, 50_00]])
        ->and($doneVat)->toBeGreaterThan(0)
        ->and([$days[0]->refundsVat->minorUnits, $days[0]->waitingRefundsVat->minorUnits, $days[0]->waiting[0]->estimateVat->minorUnits])->toBe([$doneVat, $openVat, $openVat])
        ->and(salesReports()->revenueByDay($sa, CarbonImmutable::now(), CarbonImmutable::now()->subDay()))->toBe([])
        ->and(fn () => salesReports()->revenueByDay(StoreId::fromString(strtolower((string) Str::ulid())), CarbonImmutable::now()->subDay(), CarbonImmutable::now()))
        ->toThrow(InvalidArgumentException::class);
});

it('gives each size\'s units and revenue, most sold first, a page at a time, never an order that does not count', function () {
    ['variants' => $variants] = salesReportsDelivered();
    ['order' => $rejected] = salesReportsOrder();
    app(RejectOrderHandler::class)->handle(new RejectOrder($rejected, 'Out of the delivery area'));

    $sa = StoreId::fromString(Fx::storeId('sa'));
    [$from, $to] = [CarbonImmutable::now()->subHour(), CarbonImmutable::now()->addMinute()];
    $first = salesReports()->productSales($sa, $from, $to, null, 1);
    $second = salesReports()->productSales($sa, $from, $to, $first->next, 1);
    $rows = static fn (array $page): array => array_map(static fn (ProductSaleDto $one): array => [$one->variantId, $one->units, $one->revenue->minorUnits], $page);

    expect($rows($first->rows))->toBe([[$variants[0], 2, 200_00]])
        ->and($rows($second->rows))->toBe([[$variants[1], 1, 50_00]])
        ->and($second->next)->toBeNull()
        ->and($rows(salesReports()->productSales($sa, $from, $to, 'not-a-cursor', 5)->rows))->toBe([[$variants[0], 2, 200_00], [$variants[1], 1, 50_00]])
        ->and(salesReports()->productSales(StoreId::fromString(Fx::storeId('ae')), $from, $to)->rows)->toBe([])
        ->and(fn () => salesReports()->productSales(StoreId::fromString(strtolower((string) Str::ulid())), $from, $to))->toThrow(InvalidArgumentException::class)
        ->and(fn () => salesReports()->transfersWaiting(StoreId::fromString(strtolower((string) Str::ulid()))))->toThrow(InvalidArgumentException::class);
});

it('estimates two returns not finished on one line one after the other, each piece once', function () {
    ['order' => $order, 'customer' => $customer, 'first' => $first] = salesReportsDelivered();
    $ask = static function () use ($order, $customer, $first): string {
        Fx::actAsCustomer($customer);

        return app(RequestReturnHandler::class)->handle(new RequestReturn($order, 'DAMAGED', null, [['orderLineId' => $first, 'quantity' => 1]]));
    };
    $one = $ask();
    $two = $ask();

    $days = salesReports()->revenueByDay(StoreId::fromString(Fx::storeId('sa')), CarbonImmutable::now()->subHours(2), CarbonImmutable::now()->addMinute());
    // The line's two pieces' VAT, as two returns move it: the first floor(S / 2), the second the rest.
    $line = DB::table('sales.order_lines')->where('id', $first)->first(['quantity', 'vat_share_minor']);
    $vat = (int) $line?->vat_share_minor;

    expect((int) $line?->quantity)->toBe(2)
        ->and($vat)->toBeGreaterThan(1)
        ->and(array_map(static fn ($w): array => [$w->returnId, $w->estimate->minorUnits, $w->estimateVat->minorUnits], $days[0]->waiting ?? []))
        ->toBe([[$one, 100_00, intdiv($vat, 2)], [$two, 100_00, $vat - intdiv($vat, 2)]])
        ->and([$days[0]->waitingRefunds->minorUnits ?? null, $days[0]->waitingRefundsVat->minorUnits ?? null])->toBe([200_00, $vat]);
});
