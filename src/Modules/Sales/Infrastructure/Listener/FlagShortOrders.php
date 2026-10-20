<?php

declare(strict_types=1);

namespace Modules\Sales\Infrastructure\Listener;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\ConnectionInterface;
use Modules\Inventory\Public\Events\HoldsShort;
use Modules\Sales\Domain\Repository\Orders;
use Shared\Infrastructure\Outbox\ProcessedEvents;

/**
 * Inventory let staff remove stock held for orders (inventory.md §6.1): the orders still open and
 * unshipped among those it names are flagged "stock was reduced" for staff (sales.md §1.11) — once each,
 * with a history row. **The flag only marks the order** — it never blocks shipping (answer 36). From the
 * queue, retried on failure; each event acted on once (`processed_events`, §6).
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
        private readonly ProcessedEvents $processed,
    ) {}

    public function handle(HoldsShort $event): void
    {
        // Once per event (§6): a job told twice — a worker gone after its commit — flags nothing again,
        // not even an order whose flag staff cleared meanwhile.
        $this->db->transaction(fn () => $this->processed->once($event->eventId, self::class, function () use ($event): void {
            $now = CarbonImmutable::now();

            foreach ($this->orders->flagShort(strtolower($event->storeId), $event->orderIds, $now) as $orderId) {
                $this->orders->recordHistory($orderId, strtolower($event->storeId), 'SHORT_FLAGGED', null, null, $now, ['variantId' => strtolower($event->variantId)]);
            }
        }), 3);
    }
}
