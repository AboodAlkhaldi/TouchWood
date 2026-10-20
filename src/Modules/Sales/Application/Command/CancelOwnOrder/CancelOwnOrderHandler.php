<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\CancelOwnOrder;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Modules\Sales\Application\Order\OrderInputs;
use Modules\Sales\Application\Order\OrderUndoing;
use Modules\Sales\Application\Order\OwnOrderAccess;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\InvalidSalesAttribute;
use Modules\Sales\Domain\Exception\NotCancellable;
use Modules\Sales\Domain\Exception\OrderNotFound;
use Modules\Sales\Domain\Model\OrderState;
use Modules\Sales\Domain\Repository\Orders;
use Modules\Sales\Public\Events\OrderCancelled;
use Shared\Application\Unauthorized;
use Shared\Infrastructure\Outbox\Outbox;

/**
 * A customer cancels their own order until it ships (sales.md §1.6): a reason from the list, or "Other"
 * with their words. The points used come back, what it holds is freed, nothing is due any more,
 * `OrderCancelled` goes into the outbox. **Answers whether money was paid** — the page then says staff
 * will contact them about the refund (owner, 2026-10-09). An off store's placed orders too (answer 39).
 */
final readonly class CancelOwnOrderHandler
{
    public const string PERMISSION = SalesPermissions::ORDER_MANAGE_OWN;

    public function __construct(
        private OwnOrderAccess $access,
        private Orders $orders,
        private OrderUndoing $changes,
        private Outbox $outbox,
        private ConnectionInterface $db,
    ) {}

    /**
     * @return bool whether a refund is due: the order held money
     *
     * @throws Unauthorized|InvalidSalesAttribute|OrderNotFound|NotCancellable
     */
    public function handle(CancelOwnOrder $command): bool
    {
        // The reason first: whether an id is an order is never told apart by a bad reason's answer.
        [$reason, $words] = OrderInputs::customerReason($command->reason, $command->other);
        [$orderId, , $customerId] = $this->access->authorize(self::PERMISSION, $command->orderId);

        return $this->db->transaction(function () use ($orderId, $customerId, $reason, $words): bool {
            $order = $this->orders->lock($orderId);

            if ($order === null || $order->customerId !== $customerId) {
                throw new OrderNotFound;
            }

            if (! $order->isCancellable()) {
                throw new NotCancellable;
            }

            $now = CarbonImmutable::now();
            $this->orders->cancel($order->id, $reason->value, $words, null, $now);
            $this->changes->record($order, 'CANCELLED', null, $now, OrderUndoing::status($order, OrderState::CANCELLED), ['reason' => $reason->value]);
            $this->outbox->record(new OrderCancelled((string) Str::uuid(), $order->id, $order->storeId, $order->customerId, $now));
            $this->changes->undo($order, self::PERMISSION);

            return $order->held() > 0;
        }, 3);
    }
}
