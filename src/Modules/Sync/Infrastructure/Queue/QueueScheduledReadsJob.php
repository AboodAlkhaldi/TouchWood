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
 * The hour's or the night's reads queued, by the scheduler (sync.md §1.3). Each store's read is a job of
 * its own (`ReadStoreJob`).
 */
final class QueueScheduledReadsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Three tries (platform.md §3: every queued job states its own); a lost hour is the next one's. */
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    /**
     * @param  'HOURLY'|'NIGHTLY'  $kind
     */
    public function __construct(
        public readonly string $kind,
    ) {}

    public function handle(QueueScheduledReadsHandler $handler): void
    {
        $handler->handle(new QueueScheduledReads($this->kind));
    }
}
