<?php

declare(strict_types=1);

namespace Modules\Inventory\Infrastructure\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Inventory\Application\Command\CountLowStock\CountLowStock;
use Modules\Inventory\Application\Command\CountLowStock\CountLowStockHandler;

/**
 * A store's Low Stock count, counted again (step 5); every store when none is named - each night, and
 * when a size is archived or restored. One waits per store (`low_counts.recount_queued`): until it
 * starts, the same store's next change queues nothing more; once it runs, a change made meanwhile
 * queues one again.
 */
final class CountLowStockJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Three tries (platform.md §3: every queued job states its own); the night's recount mends a miss. */
    public int $tries = 3;

    public function __construct(
        public readonly ?string $storeId = null,
    ) {}

    public function handle(CountLowStockHandler $handler): void
    {
        $handler->handle(new CountLowStock($this->storeId));
    }
}
