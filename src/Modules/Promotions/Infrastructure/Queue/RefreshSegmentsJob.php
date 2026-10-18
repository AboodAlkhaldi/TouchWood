<?php

declare(strict_types=1);

namespace Modules\Promotions\Infrastructure\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Promotions\Application\Command\RefreshSegments\RefreshSegments;
use Modules\Promotions\Application\Command\RefreshSegments\RefreshSegmentsHandler;

/**
 * The nightly refresh of every store's segments (promotions.md §1.5), queued by the scheduler as the
 * system — never run in the scheduler's own process.
 */
final class RefreshSegmentsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * One try (platform.md §3: every queued job states its own): each segment is refreshed in its own
     * transaction, and tomorrow's run takes whatever this one left.
     */
    public int $tries = 1;

    /**
     * Every store's segments, thousands of customers each, take minutes — never the worker's default
     * 60 seconds, after which the later segments would never be refreshed (step 4a's review). The
     * queue's `retry_after` must stay above it where this runs (config/queue.php).
     */
    public int $timeout = 3600;

    public int $uniqueFor = 3600;

    public function handle(RefreshSegmentsHandler $handler): void
    {
        $handler->handle(new RefreshSegments);
    }
}
