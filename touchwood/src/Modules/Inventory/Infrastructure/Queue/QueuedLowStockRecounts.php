<?php

declare(strict_types=1);

namespace Modules\Inventory\Infrastructure\Queue;

use Illuminate\Database\Connection;
use Modules\Inventory\Application\Stock\LowStockRecounts;
use Modules\Inventory\Domain\Repository\LowCounts;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Domain\ValueObject\StoreId;

/**
 * The recount queued as `CountLowStockJob` once the change commits, and run 30 seconds later: one waits
 * per store (`low_counts.recount_queued`), so a burst - a provider's read writing 500 sizes a batch, a
 * store file, many products archived - is counted once, not once a change. The menu's count is about
 * half a minute behind the list at most. A waiting mark whose job was lost is cleared by the night's
 * count of every store.
 *
 * The mark is taken after the commit, outside the change's transaction: taken inside, its row would stay
 * locked until the change commits, and every change in the store - a checkout's hold among them - would
 * wait on that one row. A mark still set then is one whose job has not started, so it counts what this
 * change committed.
 */
final readonly class QueuedLowStockRecounts implements LowStockRecounts
{
    public const int WAIT_SECONDS = 30;

    public function __construct(
        private LowCounts $counts,
        private PlatformApi $platform,
        private Connection $db,
    ) {}

    public function queue(StoreId $store): void
    {
        $this->db->afterCommit(fn () => $this->claim($store));
    }

    public function queueAll(): void
    {
        $this->db->afterCommit(function (): void {
            foreach ($this->platform->allStores() as $store) {
                $this->claim($store->storeId());
            }
        });
    }

    private function claim(StoreId $store): void
    {
        if ($this->counts->claimRecount($store)) {
            CountLowStockJob::dispatch($store->value)->delay(self::WAIT_SECONDS);
        }
    }
}
