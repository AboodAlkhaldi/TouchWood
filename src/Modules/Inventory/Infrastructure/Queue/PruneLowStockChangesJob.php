<?php

declare(strict_types=1);

namespace Modules\Inventory\Infrastructure\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Inventory\Application\Command\PruneLowStockChanges\PruneLowStockChanges;
use Modules\Inventory\Application\Command\PruneLowStockChanges\PruneLowStockChangesHandler;

/**
 * The low-stock change log's nightly removal (amendment 1), queued by the scheduler
 * (InventoryServiceProvider): scheduled work runs as a queued job (owner's decision, 2026-09-18). Unique:
 * while one waits or runs, no second one is queued.
 */
final class PruneLowStockChangesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** One try (platform.md §3: every queued job states its own): the next night takes whatever this one left. */
    public int $tries = 1;

    public int $uniqueFor = 3600;

    public function handle(PruneLowStockChangesHandler $handler): void
    {
        $handler->handle(new PruneLowStockChanges);
    }
}
