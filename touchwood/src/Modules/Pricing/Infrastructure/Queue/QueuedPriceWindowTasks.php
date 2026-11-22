<?php

declare(strict_types=1);

namespace Modules\Pricing\Infrastructure\Queue;

use Carbon\CarbonImmutable;
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
            // The queue keeps whole seconds only: a moment with a fraction would come due before it,
            // and the task push the price of the moment before. Rounded up, it never runs early.
            ApplyPriceWindowJob::dispatch($store->value, $saleId, $moment->format('Y-m-d H:i:s.uP'))
                ->delay(CarbonImmutable::instance($moment)->ceilSecond())
                ->afterCommit();
        }
    }
}
