<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sales\Application\Command\ApproveOrder\ApproveOrder;
use Modules\Sales\Application\Command\ApproveOrder\ApproveOrderHandler;
use Modules\Sales\Application\Command\MarkCameBack\MarkCameBack;
use Modules\Sales\Application\Command\MarkCameBack\MarkCameBackHandler;
use Modules\Sales\Application\Command\MarkDelivered\MarkDelivered;
use Modules\Sales\Application\Command\MarkDelivered\MarkDeliveredHandler;
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
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Sales\Support\CheckoutFixtures as Cx;

use function Pest\Laravel\seed;

/*
| `SalesReports` (sales.md §2.3; ops.md §1.5, §1.11, §2.3): an order and a return as Ops' messages need
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
        SalesPermissions::ORDER_APPROVE, SalesPermissions::ORDER_FULFIL, SalesPermissions::PAYMENT_RECORD, SalesPermissions::RETURN_MANAGE,
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
    // Placed yesterday: rejected and come back today still count today.
    DB::table('sales.orders')->update(['placed_at' => CarbonImmutable::now()->subDay()]);
    salesReportsOrder();

    $sa = StoreId::fromString(Fx::storeId('sa'));
    $today = salesReports()->counts($sa, CarbonImmutable::now()->subHours(2), CarbonImmutable::now()->addMinute());
    $none = salesReports()->counts($sa, CarbonImmutable::now(), CarbonImmutable::now()->subDay());

    expect([$today->placed, $today->individuals, $today->companies, $today->cancelled, $today->rejected, $today->cameBack])->toBe([1, 1, 0, 0, 1, 1])
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
