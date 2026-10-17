<?php

declare(strict_types=1);

namespace Modules\Sync\Infrastructure\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Sync\Application\Command\PruneSyncLog\PruneSyncLog;
use Modules\Sync\Application\Command\PruneSyncLog\PruneSyncLogHandler;

/**
 * Sync's old log cleared, by the scheduler, each night (sync.md §1.6).
 */
final class PruneSyncLogJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Three tries (platform.md §3: every queued job states its own); a night missed is the next one's. */
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60];

    public function handle(PruneSyncLogHandler $handler): void
    {
        $handler->handle(new PruneSyncLog);
    }
}
