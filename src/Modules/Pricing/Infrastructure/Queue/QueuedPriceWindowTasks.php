<?php

declare(strict_types=1);

namespace Modules\Pricing\Infrastructure\Queue;

use Modules\Pricing\Application\Prices\PriceWindowTasks;
use Shared\Domain\ValueObject\StoreId;

/**
 * Each moment a queued job delayed to it, sent once the change's transaction commits (pricing.md §1.10).
 */
final readonly class QueuedPriceWindowTasks implements PriceWindowTasks
{
    public function queue(StoreId $store, string $saleId, array $moments): void
    {
        foreach ($moments as $moment) {
            ApplyPriceWindowJob::dispatch($store->value, $saleId, $moment->format('Y-m-d H:i:s.uP'))
                ->delay($moment)
                ->afterCommit();
        }
    }
}
