<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\RejectOrder;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Sales\Application\Order\OrderAccess;
use Modules\Sales\Application\Order\OrderInputs;
use Modules\Sales\Application\Order\OrderUndoing;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\InvalidOrderChange;
use Modules\Sales\Domain\Exception\InvalidSalesAttribute;
use Modules\Sales\Domain\Exception\OrderNotFound;
use Modules\Sales\Domain\Model\OrderState;
use Modules\Sales\Domain\Repository\Orders;
use Shared\Application\Unauthorized;

/**
 * Rejecting an order waiting for approval (sales.md §1.4, §1.6), with a reason the customer sees — **a
 * rejection is not a cancellation**: it records the decision; what the order holds is freed and its
 * points come back as for a cancellation, and nothing is published yet (§2.2). Audited.
 */
final readonly class RejectOrderHandler
{
    public const string PERMISSION = SalesPermissions::ORDER_APPROVE;

    public function __construct(
        private OrderAccess $access,
        private Orders $orders,
        private OrderUndoing $changes,
        private ConnectionInterface $db,
    ) {}

    /**
     * @throws Unauthorized|InvalidSalesAttribute|OrderNotFound|InvalidOrderChange
     */
    public function handle(RejectOrder $command): void
    {
        [$orderId, , $staffId] = $this->access->authorize(self::PERMISSION, $command->orderId);
        $reason = OrderInputs::words($command->reason);

        $this->db->transaction(function () use ($orderId, $staffId, $reason): void {
            $order = $this->orders->lock($orderId) ?? throw new OrderNotFound;

            if (! $order->isWaiting()) {
                throw new InvalidOrderChange;
            }

            $now = CarbonImmutable::now();
            $this->orders->reject($order->id, $staffId, $reason, $now);
            $this->changes->record($order, 'REJECTED', $staffId, $now, OrderState::REJECTED, ['reason' => $reason]);
            $this->changes->undo($order, self::PERMISSION);
        }, 3);
    }
}
