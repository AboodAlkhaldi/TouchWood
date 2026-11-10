<?php

declare(strict_types=1);

namespace Modules\Content\Infrastructure\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Content\Application\Command\BuildSitemaps\BuildSitemaps;
use Modules\Content\Application\Command\BuildSitemaps\BuildSitemapsHandler;

/**
 * Every on store's sitemap built again (content.md §1.14): nightly, and when asked for (W10, a store switched on or
 * off, Catalog's changes at most once a day more). A failed run leaves the last files served.
 */
final class BuildSitemapsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** Three tries (platform.md §3: every queued job states its own); the night's build mends a miss. */
    public int $tries = 3;

    /** A large catalog's files take a while: ten minutes. */
    public int $timeout = 600;

    public function handle(BuildSitemapsHandler $handler): void
    {
        $handler->handle(new BuildSitemaps);
    }
}
