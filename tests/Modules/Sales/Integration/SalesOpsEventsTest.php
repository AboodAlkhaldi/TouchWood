<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Sales\Application\Command\AcceptReturn\AcceptReturn;
use Modules\Sales\Application\Command\AcceptReturn\AcceptReturnHandler;
use Modules\Sales\Application\Command\ApproveOrder\ApproveOrder;
use Modules\Sales\Application\Command\ApproveOrder\ApproveOrderHandler;
use Modules\Sales\Application\Command\EditOrder\EditOrder;
use Modules\Sales\Application\Command\EditOrder\EditOrderHandler;
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
use Modules\Sales\Domain\Exception\InvalidOrderChange;
use Modules\Sales\Public\Events\OrderApproved;
use Modules\Sales\Public\Events\OrderCameBack;
use Modules\Sales\Public\Events\OrderEdited;
use Modules\Sales\Public\Events\OrderRejected;
use Modules\Sales\Public\Events\OrderShipped;
use Modules\Sales\Public\Events\PaymentRecorded;
use Modules\Sales\Public\Events\RefundRecorded;
use Modules\Sales\Public\Events\ReturnAccepted;
use Modules\Sales\Public\Events\ReturnReceived;
use Modules\Sales\Public\Events\ReturnRefused;
use Modules\Sales\Public\Events\ReturnRequested;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Sales\Support\CheckoutFixtures as Cx;

use function Pest\Laravel\seed;

/*
| The events Sales publishes for Ops (sales.md §6; ops.md §1.5, §1.6, §2.3): each change of an order or a
| return Ops writes about, through the outbox inside the change's transaction, ids only — and nothing for
| a change refused.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

/**
 * The events of a kind in the outbox, as their payloads.
 *
 * @return list<array<string, mixed>>
 */
function salesOpsEvents(string $type): array
{
    return array_values(DB::table('public.outbox_messages')->where('type', $type)->orderBy('id')->pluck('payload')
        ->map(static fn (string $payload): array => (array) json_decode($payload, true, flags: JSON_THROW_ON_ERROR))->all());
}

/** @return array{order: string, customer: string, variants: list<string>} a placed order, and staff who may do everything to it */
function salesOpsOrder(): array
{
    $ready = Cx::ready();
    $order = Cx::place(Cx::start($ready['address'])->id)->id;
    Fx::actAsStaff(Fx::staffWith([
        SalesPermissions::ORDER_APPROVE, SalesPermissions::ORDER_FULFIL, SalesPermissions::ORDER_EDIT,
        SalesPermissions::PAYMENT_RECORD, SalesPermissions::RETURN_MANAGE,
    ], ['sa']));

    return ['order' => $order, 'customer' => $ready['customer'], 'variants' => $ready['variants']];
}

function salesOpsPayAndShip(string $order): string
{
    app(ApproveOrderHandler::class)->handle(new ApproveOrder($order));
    $payment = app(RecordPaymentHandler::class)->handle(new RecordPayment($order, 'BANK_TRANSFER', 'T-1', null, '2026-10-19', (string) Str::uuid()));
    app(ShipOrderHandler::class)->handle(new ShipOrder($order, 'SMSA', 'T-1'));

    return $payment;
}

/**
 * The ids every event carries, and its own.
 *
 * @param  array<string, mixed>  $event
 * @return array<string, mixed>
 */
function salesOpsIds(array $event, string ...$keys): array
{
    return array_intersect_key($event, array_flip(['orderId', 'storeId', 'customerId', ...$keys]));
}

it('follows an order from approval to its money given back, an event for each change, with its ids', function () {
    ['order' => $order, 'customer' => $customer] = salesOpsOrder();
    $payment = salesOpsPayAndShip($order);
    app(MarkDeliveredHandler::class)->handle(new MarkDelivered($order));
    $line = (string) DB::table('sales.order_lines')->where('order_id', $order)->where('is_gift', false)->orderBy('id')->value('id');
    Fx::actAsCustomer($customer);
    $return = app(RequestReturnHandler::class)->handle(new RequestReturn($order, 'DAMAGED', null, [['orderLineId' => $line, 'quantity' => 1]]));
    Fx::actAsStaff(Fx::staffWith([SalesPermissions::RETURN_MANAGE, SalesPermissions::PAYMENT_RECORD], ['sa']));
    app(AcceptReturnHandler::class)->handle(new AcceptReturn($return));
    app(ReceiveReturnHandler::class)->handle(new ReceiveReturn($return, [['orderLineId' => $line, 'received' => 1, 'restocked' => 1]]));
    $refund = app(RecordRefundHandler::class)->handle(new RecordRefund($order, '10.00', 'OTHER', null, 'Cash at the counter', '2026-10-19', (string) Str::uuid()));

    $ids = ['orderId' => $order, 'storeId' => Fx::storeId('sa'), 'customerId' => $customer];

    foreach ([OrderApproved::class, OrderShipped::class] as $type) {
        expect(salesOpsEvents($type))->toHaveCount(1)
            ->and(salesOpsIds(salesOpsEvents($type)[0]))->toEqual($ids);
    }

    foreach ([ReturnRequested::class, ReturnAccepted::class, ReturnReceived::class] as $type) {
        expect(salesOpsEvents($type))->toHaveCount(1)
            ->and(salesOpsIds(salesOpsEvents($type)[0], 'returnId'))->toEqual([...$ids, 'returnId' => $return]);
    }

    expect(salesOpsIds(salesOpsEvents(PaymentRecorded::class)[0] ?? [], 'moneyRecordId'))->toEqual([...$ids, 'moneyRecordId' => $payment])
        ->and(salesOpsIds(salesOpsEvents(RefundRecorded::class)[0] ?? [], 'moneyRecordId'))->toEqual([...$ids, 'moneyRecordId' => $refund])
        ->and(Str::isUuid((string) (salesOpsEvents(OrderApproved::class)[0]['eventId'] ?? '')))->toBeTrue()
        ->and(salesOpsEvents(OrderRejected::class))->toBe([]);
});

it('records a payment sent twice once, and none for one refused', function () {
    ['order' => $order] = salesOpsOrder();
    app(ApproveOrderHandler::class)->handle(new ApproveOrder($order));
    $request = (string) Str::uuid();
    $first = app(RecordPaymentHandler::class)->handle(new RecordPayment($order, 'BANK_TRANSFER', 'T-1', null, '2026-10-19', $request));
    $again = app(RecordPaymentHandler::class)->handle(new RecordPayment($order, 'BANK_TRANSFER', 'T-1', null, '2026-10-19', $request));

    // Nothing is owed any more: a second payment is refused.
    expect(fn () => app(RecordPaymentHandler::class)->handle(new RecordPayment($order, 'BANK_TRANSFER', 'T-2', null, '2026-10-19', (string) Str::uuid())))->toThrow(InvalidOrderChange::class)
        ->and($again)->toBe($first)
        ->and(array_column(salesOpsEvents(PaymentRecorded::class), 'moneyRecordId'))->toBe([$first]);
});

it('records an order rejected, and nothing for a decision refused', function () {
    ['order' => $order] = salesOpsOrder();
    app(RejectOrderHandler::class)->handle(new RejectOrder($order, 'Out of the delivery area'));

    expect(fn () => app(ApproveOrderHandler::class)->handle(new ApproveOrder($order)))->toThrow(InvalidOrderChange::class)
        ->and(salesOpsEvents(OrderRejected::class))->toHaveCount(1)
        ->and(salesOpsEvents(OrderApproved::class))->toBe([]);
});

it('records an order edited, with its edit', function () {
    ['order' => $order, 'variants' => $variants] = salesOpsOrder();
    $edit = app(EditOrderHandler::class)->handle(new EditOrder($order, 'Called the customer', [
        ['variantId' => $variants[0], 'mode' => 'RETAIL', 'quantity' => 1],
        ['variantId' => $variants[1], 'mode' => 'RETAIL', 'quantity' => 1],
    ], null, 'KEEP'));

    expect(array_column(salesOpsEvents(OrderEdited::class), 'editId'))->toBe([$edit])
        ->and(array_column(salesOpsEvents(OrderEdited::class), 'orderId'))->toBe([$order]);
});

it('records a parcel that came back', function () {
    ['order' => $order, 'variants' => $variants] = salesOpsOrder();
    salesOpsPayAndShip($order);
    app(MarkCameBackHandler::class)->handle(new MarkCameBack($order, [['variantId' => $variants[0], 'quantity' => 1]]));

    expect(array_column(salesOpsEvents(OrderCameBack::class), 'orderId'))->toBe([$order]);
});

it('records a return refused', function () {
    ['order' => $order, 'customer' => $customer] = salesOpsOrder();
    salesOpsPayAndShip($order);
    app(MarkDeliveredHandler::class)->handle(new MarkDelivered($order));
    $line = (string) DB::table('sales.order_lines')->where('order_id', $order)->where('is_gift', false)->orderBy('id')->value('id');
    Fx::actAsCustomer($customer);
    $return = app(RequestReturnHandler::class)->handle(new RequestReturn($order, 'CHANGED_MIND', null, [['orderLineId' => $line, 'quantity' => 1]]));
    Fx::actAsStaff(Fx::staffWith([SalesPermissions::RETURN_MANAGE], ['sa']));
    app(RefuseReturnHandler::class)->handle(new RefuseReturn($return, 'Opened and used'));

    expect(array_column(salesOpsEvents(ReturnRefused::class), 'returnId'))->toBe([$return])
        ->and(salesOpsEvents(ReturnAccepted::class))->toBe([]);
});
