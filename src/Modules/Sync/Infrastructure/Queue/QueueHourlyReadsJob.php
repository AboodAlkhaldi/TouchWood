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
 * The hour's reads queued, by the scheduler (sync.md §1.3): one for every wired store. A class of its
 * own, not the night's with an argument: the scheduler names an event - and its one-server lock - after
 * its job's class, so one class for both would let the hour's take the night's lock at 02:00.
 */
final class QueueHourlyReadsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Three tries (platform.md §3: every queued job states its own); a lost hour is the next one's. */
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function handle(QueueScheduledReadsHandler $handler): void
    {
        $handler->handle(new QueueScheduledReads('HOURLY'));
    }
}
