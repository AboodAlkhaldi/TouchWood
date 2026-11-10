<?php

declare(strict_types=1);

namespace Modules\Content\Infrastructure\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Content\Application\Command\BuildSitemaps\BuildSitemaps;
use Modules\Content\Application\Command\BuildSitemaps\BuildSitemapsHandler;
use Modules\Platform\Public\LongQueue;

/**
 * Every on store's sitemap built again (content.md §1.14): nightly, and when asked for (W10, a store switched on or
 * off, Catalog's changes at most once a day more). A failed run leaves the last files served.
 *
 * **One build at a time** (PR 4 review): the nightly one is queued without asking, a second can be asked for while
 * one runs, and a database queue hands a job still running to another worker once its connection's `retry_after` has
 * passed. It runs on the long connection (3700 s, above its ten minutes; owner, 2026-11-10), and a copy of this very
 * job still deletes itself (`RunOnce`) should one be handed out; a build holds one lock for every build
 * (`OneSitemapBuild`): another build that finds it held goes back to the queue for a minute and builds after, with
 * what changed meanwhile.
 */
final class BuildSitemapsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Released while another build runs, so a bound on attempts, not a deadline (lesson 250: no `retryUntil`, which
     * Platform's retry would carry past): 240 tries, four hours of waiting a minute at a time; three failures end it
     * sooner. Nothing loops for ever.
     */
    public int $tries = 240;

    public int $maxExceptions = 3;

    /** A large catalog's files take a while: ten minutes. */
    public int $timeout = 600;

    public function __construct()
    {
        $this->onConnection(LongQueue::connection());
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [app(RunOnce::class), app(OneSitemapBuild::class)];
    }

    public function handle(BuildSitemapsHandler $handler): void
    {
        $handler->handle(new BuildSitemaps);
    }
}
