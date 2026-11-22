<?php

declare(strict_types=1);

namespace Modules\Catalog\Infrastructure\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Catalog\Application\Command\PruneSearchLog\PruneSearchLog;
use Modules\Catalog\Application\Command\PruneSearchLog\PruneSearchLogHandler;

/**
 * The search log's nightly removal, queued by the scheduler (CatalogServiceProvider). Scheduled
 * work runs as a queued job (owner's decision, 2026-09-18). Unique: while one waits or runs, no
 * second one is queued.
 */
final class PruneSearchLogJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * One try (platform.md §3: every queued job states its own): tomorrow's run takes whatever this
     * one left.
     */
    public int $tries = 1;

    public int $uniqueFor = 3600;

    public function handle(PruneSearchLogHandler $handler): void
    {
        $handler->handle(new PruneSearchLog);
    }
}
