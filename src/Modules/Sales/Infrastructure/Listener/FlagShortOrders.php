<?php

declare(strict_types=1);

namespace Modules\Sales\Infrastructure\Listener;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\ConnectionInterface;
use Modules\Inventory\Public\Events\HoldsShort;
use Modules\Sales\Domain\Repository\Orders;

/**
 * Inventory let staff remove stock held for orders (inventory.md §6.1): the orders still open and
 * unshipped among those it names are flagged "stock was reduced" for staff (sales.md §1.11) — once each,
 * with a history row. **The flag only marks the order** — it never blocks shipping (answer 36). From the
 * queue, retried on failure; done once however many times it runs.
 */
final class FlagShortOrders implements ShouldQueue
{
    /** Five attempts over about 36 minutes: the database away. */
    public int $tries = 5;

    /** @var list<int> seconds before each retry */
    public array $backoff = [10, 60, 300, 1800];

    public function __construct(
        private readonly Orders $orders,
        private readonly ConnectionInterface $db,
    ) {}

    public function handle(HoldsShort $event): void
    {
        $this->db->transaction(function () use ($event): void {
            $now = CarbonImmutable::now();

            foreach ($this->orders->flagShort(strtolower($event->storeId), $event->orderIds, $now) as $orderId) {
                $this->orders->recordHistory($orderId, strtolower($event->storeId), 'SHORT_FLAGGED', null, null, $now, ['variantId' => strtolower($event->variantId)]);
            }
        }, 3);
    }
}
