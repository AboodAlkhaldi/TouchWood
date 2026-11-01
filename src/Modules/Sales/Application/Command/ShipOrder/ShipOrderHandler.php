<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\ShipOrder;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Modules\Inventory\Public\Contracts\InventoryApi;
use Modules\Inventory\Public\Dto\HoldLineDto;
use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Sales\Application\Order\OrderAccess;
use Modules\Sales\Application\Order\OrderUndoing;
use Modules\Sales\Application\Order\ShipmentInputs;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\InvalidOrderChange;
use Modules\Sales\Domain\Exception\InvalidSalesAttribute;
use Modules\Sales\Domain\Exception\NotShippable;
use Modules\Sales\Domain\Exception\OrderNotFound;
use Modules\Sales\Domain\Exception\ShippingFeeNotSet;
use Modules\Sales\Domain\Model\OrderState;
use Modules\Sales\Domain\Repository\Orders;
use Modules\Sales\Public\Events\OrderShipped;
use Shared\Application\Unauthorized;
use Shared\Infrastructure\Outbox\Outbox;

/**
 * Staff mark an approved order shipped (sales.md §1.5, §9.5 #3) — **one shipment per order**: one of the
 * store's carriers or "Other carrier" typed, the tracking number and/or link (`ShipmentInputs`: a typed link
 * wins, else the carrier's pattern — stored whole, so a carrier changed or deleted later breaks nothing).
 * Refused (`NotShippable`) while not approved, or while what it holds is less than is due without "may
 * ship unpaid" (§1.4); while its shipping fee is **to be set** (`ShippingFeeNotSet`, gap 6.2); **an order flagged "stock was reduced" ships**
 * (answer 36). Inventory's `ship` takes the stock off — **once per order**, under the order's lock, after
 * the shipment is written (stage 5: a repeated partial shipment would be taken twice), last in the
 * transaction (its store lock, then Catalog's); never refused for the stock (answer 41). Audited — the
 * carrier, the tracking number and the link, partly typed by staff, as written only: they stay on the
 * order's row.
 */
final readonly class ShipOrderHandler
{
    public const string PERMISSION = SalesPermissions::ORDER_FULFIL;

    public function __construct(
        private OrderAccess $access,
        private Orders $orders,
        private OrderUndoing $changes,
        private ShipmentInputs $inputs,
        private InventoryApi $inventory,
        private ConnectionInterface $db,
        private Outbox $outbox,
    ) {}

    /**
     * @throws Unauthorized|InvalidSalesAttribute|OrderNotFound|InvalidOrderChange|NotShippable|ShippingFeeNotSet
     */
    public function handle(ShipOrder $command): void
    {
        [$orderId, $storeId, $staffId] = $this->access->authorize(self::PERMISSION, $command->orderId);
        $shipment = $this->inputs->of($storeId->value, $command->carrierId, $command->otherCarrier, $command->trackingNumber, $command->trackingLink);

        $this->db->transaction(function () use ($orderId, $staffId, $shipment): void {
            $order = $this->orders->lock($orderId) ?? throw new OrderNotFound;

            if ($order->shipmentStatus !== OrderState::NOT_SHIPPED) {
                throw new InvalidOrderChange;
            }

            // The fee first: until it is set, what is due is not known either.
            if ($order->shippingFeePending) {
                throw new ShippingFeeNotSet;
            }

            if (! $order->isApproved() || ($order->held() < $order->due && ! $order->mayShipUnpaid)) {
                throw new NotShippable;
            }

            $now = CarbonImmutable::now();
            $this->orders->ship($order->id, $shipment, $now);
            $this->outbox->record(new OrderShipped((string) Str::uuid(), $order->id, $order->storeId, $order->customerId, $now));
            $this->changes->record($order, 'SHIPPED', $staffId, $now, AuditChanges::none()
                ->changed('shipment_status', $order->shipmentStatus, OrderState::SHIPPED)->personal('carrier')->personal('tracking_number')->personal('tracking_link'));

            // Last, once: the stock taken off.
            $this->inventory->ship($order->id, array_map(
                static fn (array $piece): HoldLineDto => new HoldLineDto($piece['variantId'], $piece['quantity'], $piece['gift']),
                $this->orders->pieces($order->id),
            ));
        }, 3);
    }
}
