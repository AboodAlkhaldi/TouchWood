<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Command\CancelOrder;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Modules\Sales\Application\Order\OrderAccess;
use Modules\Sales\Application\Order\OrderInputs;
use Modules\Sales\Application\Order\OrderUndoing;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\InvalidSalesAttribute;
use Modules\Sales\Domain\Exception\NotCancellable;
use Modules\Sales\Domain\Exception\OrderNotFound;
use Modules\Sales\Domain\Model\OrderState;
use Modules\Sales\Domain\Repository\Orders;
use Modules\Sales\Public\Enums\CancelReason;
use Modules\Sales\Public\Events\OrderCancelled;
use Shared\Application\Unauthorized;
use Shared\Infrastructure\Outbox\Outbox;

/**
 * Staff cancel an order before it ships (sales.md §1.6) — waiting or approved — `BY_STAFF`, with their
 * words: the points used come back, what it holds is freed, nothing is due any more, `OrderCancelled`
 * goes into the outbox. Audited. An off store's orders too: its staff finish them (§1.12).
 */
final readonly class CancelOrderHandler
{
    public const string PERMISSION = SalesPermissions::ORDER_CANCEL;

    public function __construct(
        private OrderAccess $access,
        private Orders $orders,
        private OrderUndoing $changes,
        private Outbox $outbox,
        private ConnectionInterface $db,
    ) {}

    /**
     * @throws Unauthorized|InvalidSalesAttribute|OrderNotFound|NotCancellable
     */
    public function handle(CancelOrder $command): void
    {
        [$orderId, , $staffId] = $this->access->authorize(self::PERMISSION, $command->orderId);
        $words = OrderInputs::words($command->reason);

        $this->db->transaction(function () use ($orderId, $staffId, $words): void {
            $order = $this->orders->lock($orderId) ?? throw new OrderNotFound;

            if (! $order->isCancellable()) {
                throw new NotCancellable;
            }

            $now = CarbonImmutable::now();
            $this->orders->cancel($order->id, CancelReason::ByStaff->value, $words, $staffId, $now);
            $this->changes->record($order, 'CANCELLED', $staffId, $now, OrderState::CANCELLED, ['reason' => CancelReason::ByStaff->value, 'words' => $words]);
            $this->outbox->record(new OrderCancelled((string) Str::uuid(), $order->id, $order->storeId, $order->customerId, $now));
            $this->changes->undo($order, self::PERMISSION);
        }, 3);
    }
}
