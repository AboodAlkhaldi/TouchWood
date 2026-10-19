<?php

declare(strict_types=1);

namespace Modules\Pricing\Infrastructure\Queue;

use Illuminate\Database\Connection;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Pricing\Application\Prices\UnpricedRecounts;
use Modules\Pricing\Domain\Repository\UnpricedCounts;
use Shared\Domain\ValueObject\StoreId;

/**
 * The recount queued as `CountUnpricedSizesJob` once the change commits, and run 30 seconds later: one
 * waits per store (`unpriced_counts.recount_queued`), so a burst - a provider's read writing 500 prices a
 * batch, a store file, many sizes switched on - is counted once, not once a change. The menu's count is
 * about half a minute behind the list at most. A waiting mark whose job was lost is cleared by the night's
 * count of every store. As Inventory's `QueuedLowStockRecounts`.
 *
 * The mark is taken after the commit, outside the change's transaction: taken inside, its row would stay
 * locked until the change commits, and every price change in the store would wait on that one row. A mark
 * still set then is one whose job has not started, so it counts what this change committed.
 */
final readonly class QueuedUnpricedRecounts implements UnpricedRecounts
{
    public const int WAIT_SECONDS = 30;

    public function __construct(
        private UnpricedCounts $counts,
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
            CountUnpricedSizesJob::dispatch($store->value)->delay(self::WAIT_SECONDS);
        }
    }
}
