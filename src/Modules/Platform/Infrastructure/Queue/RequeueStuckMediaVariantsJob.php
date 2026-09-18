<?php

declare(strict_types=1);

namespace Modules\Platform\Infrastructure\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Platform\Application\Command\RequeueStuckMediaVariants\RequeueStuckMediaVariants;
use Modules\Platform\Application\Command\RequeueStuckMediaVariants\RequeueStuckMediaVariantsHandler;

/**
 * The sweep, queued by the scheduler every ten minutes (PlatformServiceProvider). Scheduled work
 * runs as a queued job so its audit source is JOB (owner's decision, 2026-09-18). Unique: a sweep
 * still waiting or running is never queued a second time.
 */
final class RequeueStuckMediaVariantsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Released after ten minutes even if a worker died mid-sweep, so the next run is not lost. */
    public int $uniqueFor = 600;

    public function handle(RequeueStuckMediaVariantsHandler $handler): void
    {
        $handler->handle(new RequeueStuckMediaVariants);
    }
}
