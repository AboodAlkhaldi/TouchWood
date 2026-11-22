<?php

declare(strict_types=1);

namespace Modules\Content\Infrastructure\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Content\Application\Command\CheckRedirects\CheckRedirects;
use Modules\Content\Application\Command\CheckRedirects\CheckRedirectsHandler;
use Modules\Platform\Public\LongQueue;

/**
 * Check Addresses (content.md §1.15, W11), a page of lines a job: each page done queues the next, so no run lasts
 * longer than a worker allows however long the list.
 */
final class CheckRedirectsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Three tries (platform.md §3); a page asked again marks its lines again, which is harmless. */
    public int $tries = 3;

    /**
     * A hundred lines, each asked within ten seconds and a few hops: well inside twenty minutes. On the long
     * connection, whose `retry_after` (3700 s) is above it; each next page too, built by this constructor.
     */
    public int $timeout = 1200;

    public function __construct(
        public readonly ?string $after = null,
    ) {
        $this->onConnection(LongQueue::connection());
    }

    /**
     * A copy a second worker takes of a page still running, or done, should the queue hand one out, deletes itself:
     * run again it would ask the page's addresses twice and queue the next page twice, doubling the chain at each
     * long page (PR 4 review).
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [app(RunOnce::class)];
    }

    public function handle(CheckRedirectsHandler $handler): void
    {
        $next = $handler->handle(new CheckRedirects($this->after));

        if ($next !== null) {
            self::dispatch($next);
        }
    }
}
