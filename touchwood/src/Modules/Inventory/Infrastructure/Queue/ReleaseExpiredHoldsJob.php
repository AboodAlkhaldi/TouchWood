<?php

declare(strict_types=1);

namespace Modules\Inventory\Infrastructure\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Inventory\Application\Command\ReleaseExpiredHolds\ReleaseExpiredHolds;
use Modules\Inventory\Application\Command\ReleaseExpiredHolds\ReleaseExpiredHoldsHandler;

/**
 * Expired holds freed, queued by the scheduler every minute (InventoryServiceProvider; owner,
 * 2026-10-11). Scheduled work runs as a queued job (owner's decision, 2026-09-18). Unique: while one
 * waits or runs, no second one is queued.
 */
final class ReleaseExpiredHoldsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * One try (platform.md §3: every queued job states its own): the next minute's run takes whatever
     * this one left.
     */
    public int $tries = 1;

    public int $uniqueFor = 300;

    public function handle(ReleaseExpiredHoldsHandler $handler): void
    {
        $handler->handle(new ReleaseExpiredHolds);
    }
}
