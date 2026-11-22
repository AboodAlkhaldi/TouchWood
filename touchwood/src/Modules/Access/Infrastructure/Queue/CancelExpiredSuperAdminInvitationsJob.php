<?php

declare(strict_types=1);

namespace Modules\Access\Infrastructure\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Access\Application\Command\CancelExpiredSuperAdminInvitations\CancelExpiredSuperAdminInvitations;
use Modules\Access\Application\Command\CancelExpiredSuperAdminInvitations\CancelExpiredSuperAdminInvitationsHandler;

/**
 * The sweep, queued by the scheduler every ten minutes (AccessServiceProvider). Scheduled work runs
 * as a queued job, so its audit entries say JOB (owner's decision, 2026-09-18). Unique: while one
 * waits or runs, no second one is queued — for at most ten minutes.
 */
final class CancelExpiredSuperAdminInvitationsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * One try, as the worker gave it before (platform.md §3: every queued job states its own):
     * the next run, ten minutes on, takes up anything this one left.
     */
    public int $tries = 1;

    public int $uniqueFor = 600;

    public function handle(CancelExpiredSuperAdminInvitationsHandler $handler): void
    {
        $handler->handle(new CancelExpiredSuperAdminInvitations);
    }
}
