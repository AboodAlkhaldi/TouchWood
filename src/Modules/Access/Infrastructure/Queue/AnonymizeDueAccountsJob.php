<?php

declare(strict_types=1);

namespace Modules\Access\Infrastructure\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Access\Application\Command\AnonymizeDueAccounts\AnonymizeDueAccounts;
use Modules\Access\Application\Command\AnonymizeDueAccounts\AnonymizeDueAccountsHandler;

/**
 * The daily deletion sweep, queued by the scheduler at 03:00 in Riyadh (AccessServiceProvider).
 * Scheduled work runs as a queued job, so its audit entries say JOB (owner's decision, 2026-09-18).
 * Unique: while one waits or runs, no second one is queued.
 */
final class AnonymizeDueAccountsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $uniqueFor = 3600;

    public function handle(AnonymizeDueAccountsHandler $handler): void
    {
        $handler->handle(new AnonymizeDueAccounts);
    }
}
