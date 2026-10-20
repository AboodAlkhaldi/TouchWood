<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\ApproveOrder;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Sales\Application\Order\OrderAccess;
use Modules\Sales\Application\Order\OrderUndoing;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\InvalidOrderChange;
use Modules\Sales\Domain\Exception\OrderNotFound;
use Modules\Sales\Domain\Model\OrderState;
use Modules\Sales\Domain\Repository\Orders;
use Shared\Application\Unauthorized;

/** Approving an order waiting for it (sales.md §1.4): every order passes staff approval. Audited. */
final readonly class ApproveOrderHandler
{
    public const string PERMISSION = SalesPermissions::ORDER_APPROVE;

    public function __construct(
        private OrderAccess $access,
        private Orders $orders,
        private OrderUndoing $changes,
        private ConnectionInterface $db,
    ) {}

    /**
     * @throws Unauthorized|OrderNotFound|InvalidOrderChange
     */
    public function handle(ApproveOrder $command): void
    {
        [$orderId, , $staffId] = $this->access->authorize(self::PERMISSION, $command->orderId);

        $this->db->transaction(function () use ($orderId, $staffId): void {
            $order = $this->orders->lock($orderId) ?? throw new OrderNotFound;

            if (! $order->isWaiting()) {
                throw new InvalidOrderChange;
            }

            $now = CarbonImmutable::now();
            $this->orders->approve($order->id, $staffId, $now);
            $this->changes->record($order, 'APPROVED', $staffId, $now, OrderState::APPROVED);
        }, 3);
    }
}
