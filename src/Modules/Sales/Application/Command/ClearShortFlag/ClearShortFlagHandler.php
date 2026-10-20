<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\ClearShortFlag;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\ConnectionInterface;
use Modules\Platform\Public\Dto\AuditChanges;
use Modules\Sales\Application\Order\OrderAccess;
use Modules\Sales\Application\Order\OrderUndoing;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\OrderNotFound;
use Modules\Sales\Domain\Repository\Orders;
use Shared\Application\Unauthorized;

/**
 * Staff clear "stock was reduced" by hand once it is settled (sales.md §1.11): an edit does not show that
 * the shortage is gone. Clearing an order with no flag changes nothing — pressed twice, cleared once.
 * Audited.
 */
final readonly class ClearShortFlagHandler
{
    public const string PERMISSION = SalesPermissions::ORDER_EDIT;

    public function __construct(
        private OrderAccess $access,
        private Orders $orders,
        private OrderUndoing $changes,
        private ConnectionInterface $db,
    ) {}

    /**
     * @throws Unauthorized|OrderNotFound
     */
    public function handle(ClearShortFlag $command): void
    {
        [$orderId, , $staffId] = $this->access->authorize(self::PERMISSION, $command->orderId);

        $this->db->transaction(function () use ($orderId, $staffId): void {
            $order = $this->orders->lock($orderId) ?? throw new OrderNotFound;

            if ($order->shortOfStockAt !== null && $this->orders->clearShort($order->id)) {
                $changes = AuditChanges::none()->changed('short_of_stock_at', $order->shortOfStockAt->format(DateTimeInterface::ATOM), null);
                $this->changes->record($order, 'SHORT_CLEARED', $staffId, CarbonImmutable::now(), $changes);
            }
        }, 3);
    }
}
