<?php

declare(strict_types=1);

namespace Modules\Sync\Infrastructure\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Sync\Application\Command\QueueScheduledReads\QueueScheduledReads;
use Modules\Sync\Application\Command\QueueScheduledReads\QueueScheduledReadsHandler;

/**
 * The night's reads queued, by the scheduler (sync.md §1.3): one for every wired store - the full check,
 * and the only read that finds our sizes the provider does not know. A class of its own, so its
 * one-server lock is its own (`QueueHourlyReadsJob` says why).
 */
final class QueueNightlyReadsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Three tries (platform.md §3: every queued job states its own); a lost night is the next one's. */
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function handle(QueueScheduledReadsHandler $handler): void
    {
        $handler->handle(new QueueScheduledReads('NIGHTLY'));
    }
}
