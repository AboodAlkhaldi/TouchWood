<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\CorrectShipment;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Sales\Application\Order\OrderAccess;
use Modules\Sales\Application\Order\OrderUndoing;
use Modules\Sales\Application\Order\ShipmentInputs;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\InvalidOrderChange;
use Modules\Sales\Domain\Exception\InvalidSalesAttribute;
use Modules\Sales\Domain\Exception\OrderNotFound;
use Modules\Sales\Domain\Model\OrderState;
use Modules\Sales\Domain\Repository\Orders;
use Shared\Application\Unauthorized;

/**
 * Staff correct a shipped order's carrier, tracking number or link (sales.md §9.5 #3; gap 6.5) — **only
 * while it is `SHIPPED`**: once delivered or back, the shipment is history. Read as when it was marked
 * shipped (`ShipmentInputs`); nothing else moves — no stock, no event, no message. Under the order's
 * lock; in its history and audited, the typed values as written only.
 */
final readonly class CorrectShipmentHandler
{
    public const string PERMISSION = SalesPermissions::ORDER_FULFIL;

    public function __construct(
        private OrderAccess $access,
        private Orders $orders,
        private OrderUndoing $changes,
        private ShipmentInputs $inputs,
        private ConnectionInterface $db,
    ) {}

    /**
     * @throws Unauthorized|InvalidSalesAttribute|OrderNotFound|InvalidOrderChange
     */
    public function handle(CorrectShipment $command): void
    {
        [$orderId, $storeId, $staffId] = $this->access->authorize(self::PERMISSION, $command->orderId);
        $shipment = $this->inputs->of($storeId->value, $command->carrierId, $command->otherCarrier, $command->trackingNumber, $command->trackingLink);

        $this->db->transaction(function () use ($orderId, $staffId, $shipment): void {
            $order = $this->orders->lock($orderId) ?? throw new OrderNotFound;

            if ($order->shipmentStatus !== OrderState::SHIPPED) {
                throw new InvalidOrderChange;
            }

            $before = $this->orders->shipment($order->id);
            $this->orders->correctShipment($order->id, $shipment);
            $this->changes->record($order, 'SHIPMENT_CORRECTED', $staffId, CarbonImmutable::now(), AuditChanges::none()
                ->changed('carrier_id', $before?->carrierId, $shipment->carrierId)->personal('carrier')->personal('tracking_number')->personal('tracking_link'));
        }, 3);
    }
}
