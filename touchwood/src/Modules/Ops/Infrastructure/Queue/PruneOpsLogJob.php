<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Ops\Application\Command\PruneOpsLog\PruneOpsLog;
use Modules\Ops\Application\Command\PruneOpsLog\PruneOpsLogHandler;

/**
 * The nightly removal of what Ops keeps twelve months (ops.md §5), queued by the scheduler
 * (OpsServiceProvider): scheduled work runs as a queued job (owner's decision, 2026-09-18). Unique:
 * while one waits or runs, no second one is queued.
 */
final class PruneOpsLogJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** One try (platform.md §3: every queued job states its own): the next night takes whatever this one left. */
    public int $tries = 1;

    public int $uniqueFor = 3600;

    public function handle(PruneOpsLogHandler $handler): void
    {
        $handler->handle(new PruneOpsLog);
    }
}
