<?php

declare(strict_types=1);

namespace Modules\Sales\Infrastructure\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Sales\Application\Command\PushSalesRanks\PushSalesRanks;
use Modules\Sales\Application\Command\PushSalesRanks\PushSalesRanksHandler;

/** The Best-Selling ranks pushed to Catalog each night (sales.md §1.13). */
final class PushSalesRanksJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** One try (platform.md §3): tomorrow's run ranks again from scratch. */
    public int $tries = 1;

    public int $uniqueFor = 3600;

    public function handle(PushSalesRanksHandler $handler): void
    {
        $handler->handle(new PushSalesRanks);
    }
}
