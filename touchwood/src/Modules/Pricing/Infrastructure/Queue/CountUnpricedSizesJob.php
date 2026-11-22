<?php

declare(strict_types=1);

namespace Modules\Pricing\Infrastructure\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Pricing\Application\Command\CountUnpricedSizes\CountUnpricedSizes;
use Modules\Pricing\Application\Command\CountUnpricedSizes\CountUnpricedSizesHandler;

/**
 * A store's Needs a Price count, counted again (owner Q43); every store when none is named - each night,
 * and when a size or a product is archived or restored. One waits per store
 * (`unpriced_counts.recount_queued`): until it starts, the same store's next change queues nothing more;
 * once it runs, a change made meanwhile queues one again.
 */
final class CountUnpricedSizesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Three tries (platform.md §3: every queued job states its own); the night's recount mends a miss. */
    public int $tries = 3;

    public function __construct(
        public readonly ?string $storeId = null,
    ) {}

    public function handle(CountUnpricedSizesHandler $handler): void
    {
        $handler->handle(new CountUnpricedSizes($this->storeId));
    }
}
