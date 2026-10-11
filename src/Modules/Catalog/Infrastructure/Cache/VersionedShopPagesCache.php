<?php

declare(strict_types=1);

namespace Modules\Catalog\Infrastructure\Cache;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\Connection;
use Modules\Catalog\Application\Query\Shop\ShopPagesCache;
use Shared\Infrastructure\Cache\VersionedCache;

/**
 * `ShopPagesCache` on the kernel's `VersionedCache` (CONVENTIONS): the cache is in PostgreSQL, on the
 * same connection, so the version replaced inside a change commits or rolls back with it.
 *
 * **The version is read once a request** (scoped): the menu and a product's page share that read, so
 * a warm page pays one read of the version and one of each page it keeps. A change in the same
 * request forgets it, so nothing read after the change is the old version's.
 *
 * **What an old version kept is removed by the hour** (`prune`, PruneShopPagesJob): every change
 * replaces the version, and the database store deletes an expired row only when that same key is
 * asked for again, which an old version's never is.
 */
final class VersionedShopPagesCache implements ShopPagesCache
{
    /** A safety net only: every change replaces the version at once. */
    private const int SNAPSHOT_SECONDS = 3600;

    private const string NAME = 'catalog:shop';

    private readonly VersionedCache $cache;

    private ?string $version = null;

    public function __construct(
        private readonly Cache $store,
        Connection $db,
    ) {
        $this->cache = new VersionedCache($store, $db, self::NAME, self::SNAPSHOT_SECONDS);
    }

    public function remember(string $part, Closure $load): mixed
    {
        $this->version ??= $this->cache->version();

        return $this->cache->remember($load, $part, $this->version);
    }

    public function invalidate(): void
    {
        $this->version = null;
        $this->cache->invalidate();
    }

    public function prune(): int
    {
        $store = $this->store->getStore();

        if (! $store instanceof DatabaseStore) {
            return 0;
        }

        $prefix = $store->getPrefix().self::NAME.':';

        // The version's own row is kept: it never expires before ten years, and it is excluded anyway.
        return $store->getConnection()->table((string) config('cache.stores.database.table', 'cache'))
            ->where('key', 'like', str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix).'%')
            ->where('key', '<>', $prefix.'version')
            ->where('expiration', '<', CarbonImmutable::now()->getTimestamp())
            ->delete();
    }
}
