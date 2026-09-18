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
 * runs as a queued job, so anything it audits says JOB (owner's decision, 2026-09-18); the sweep
 * itself is system maintenance and audits nothing. Unique: while a sweep waits or runs, no second
 * one is queued — for at most ten minutes, so a worker that died cannot stop the sweep for good.
 */
final class RequeueStuckMediaVariantsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $uniqueFor = 600;

    public function handle(RequeueStuckMediaVariantsHandler $handler): void
    {
        $handler->handle(new RequeueStuckMediaVariants);
    }
}
