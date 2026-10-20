<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\ShipOrder;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Inventory\Public\Contracts\InventoryApi;
use Modules\Inventory\Public\Dto\HoldLineDto;
use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Sales\Application\Order\OrderAccess;
use Modules\Sales\Application\Order\OrderUndoing;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\InvalidOrderChange;
use Modules\Sales\Domain\Exception\InvalidSalesAttribute;
use Modules\Sales\Domain\Exception\NotShippable;
use Modules\Sales\Domain\Exception\OrderNotFound;
use Modules\Sales\Domain\Model\OrderState;
use Modules\Sales\Domain\Repository\Orders;
use Shared\Application\Unauthorized;

/**
 * Staff mark an approved order shipped (sales.md §1.5) — **one shipment per order**: the carrier, and a
 * tracking number when there is one. Refused (`NotShippable`) while not approved, or while what it holds
 * is less than is due without "may ship unpaid" (§1.4); **an order flagged "stock was reduced" ships**
 * (answer 36). Inventory's `ship` takes the stock off — **once per order**, under the order's lock, after
 * the shipment is written (stage 5: a repeated partial shipment would be taken twice), last in the
 * transaction (its store lock, then Catalog's); never refused for the stock (answer 41). Audited.
 */
final readonly class ShipOrderHandler
{
    public const string PERMISSION = SalesPermissions::ORDER_FULFIL;

    public function __construct(
        private OrderAccess $access,
        private Orders $orders,
        private OrderUndoing $changes,
        private InventoryApi $inventory,
        private ConnectionInterface $db,
    ) {}

    /**
     * @throws Unauthorized|InvalidSalesAttribute|OrderNotFound|InvalidOrderChange|NotShippable
     */
    public function handle(ShipOrder $command): void
    {
        [$orderId, , $staffId] = $this->access->authorize(self::PERMISSION, $command->orderId);
        $carrier = trim($command->carrier);
        $tracking = $command->trackingNumber === null || trim($command->trackingNumber) === '' ? null : trim($command->trackingNumber);

        if ($carrier === '' || mb_strlen($carrier) > 100) {
            throw new InvalidSalesAttribute('carrier', 'from 1 to 100 characters');
        }

        if ($tracking !== null && mb_strlen($tracking) > 100) {
            throw new InvalidSalesAttribute('tracking_number', 'at most 100 characters');
        }

        $this->db->transaction(function () use ($orderId, $staffId, $carrier, $tracking): void {
            $order = $this->orders->lock($orderId) ?? throw new OrderNotFound;

            if ($order->shipmentStatus !== OrderState::NOT_SHIPPED) {
                throw new InvalidOrderChange;
            }

            if (! $order->isApproved() || ($order->held() < $order->due && ! $order->mayShipUnpaid)) {
                throw new NotShippable;
            }

            $now = CarbonImmutable::now();
            $this->orders->ship($order->id, $carrier, $tracking, $now);
            $this->changes->record($order, 'SHIPPED', $staffId, $now, AuditChanges::none()
                ->changed('shipment_status', $order->shipmentStatus, OrderState::SHIPPED)->changed('carrier', null, $carrier)->changed('tracking_number', null, $tracking), ['carrier' => $carrier]);

            // Last, once: the stock taken off.
            $this->inventory->ship($order->id, array_map(
                static fn (array $piece): HoldLineDto => new HoldLineDto($piece['variantId'], $piece['quantity'], $piece['gift']),
                $this->orders->pieces($order->id),
            ));
        }, 3);
    }
}
