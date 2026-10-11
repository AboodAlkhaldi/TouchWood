<?php

declare(strict_types=1);

namespace Modules\Catalog\Infrastructure\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Catalog\Application\Query\Shop\ShopPagesCache;

/**
 * The shop's kept pages of old versions, removed by the hour (catalog.md §4.5, P21): every change
 * replaces the version, and nothing asks for an old version's pages again, so the cache table would
 * only grow. Queued by the scheduler (CatalogServiceProvider): scheduled work runs as a queued job
 * (owner's decision, 2026-09-18). Unique: while one waits or runs, no second one is queued. It removes
 * cache rows only - nothing of the catalog - so it asks no permission.
 */
final class PruneShopPagesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /** One try (platform.md §3): the next hour's run takes whatever this one left. */
    public int $tries = 1;

    public int $uniqueFor = 3600;

    public function handle(ShopPagesCache $pages): void
    {
        $pages->prune();
    }
}
