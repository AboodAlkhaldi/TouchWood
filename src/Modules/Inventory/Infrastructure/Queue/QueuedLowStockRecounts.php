<?php

declare(strict_types=1);

namespace Modules\Inventory\Infrastructure\Queue;

use Modules\Inventory\Application\Stock\LowStockRecounts;
use Modules\Inventory\Domain\Repository\LowCounts;
use Shared\Domain\ValueObject\StoreId;

/**
 * The recount queued as `CountLowStockJob` after the change commits, and run 30 seconds later: one waits
 * per store (`low_counts.recount_queued`, marked in the change's own transaction), so a burst - a
 * provider's read writing 500 sizes a batch, a store file - is counted once, not once a batch. The
 * menu's count is about half a minute behind the list at most. A waiting mark whose job was lost is
 * cleared by the night's count of every store.
 */
final readonly class QueuedLowStockRecounts implements LowStockRecounts
{
    public const int WAIT_SECONDS = 30;

    public function __construct(
        private LowCounts $counts,
    ) {}

    public function queue(StoreId $store): void
    {
        if ($this->counts->claimRecount($store)) {
            CountLowStockJob::dispatch($store->value)->delay(self::WAIT_SECONDS)->afterCommit();
        }
    }

    public function queueAll(): void
    {
        CountLowStockJob::dispatch()->delay(self::WAIT_SECONDS)->afterCommit();
    }
}
