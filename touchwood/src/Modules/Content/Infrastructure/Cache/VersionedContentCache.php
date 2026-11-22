<?php

declare(strict_types=1);

namespace Modules\Content\Infrastructure\Cache;

use Closure;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\Connection;
use Modules\Content\Application\ContentCache;
use Shared\Infrastructure\Cache\VersionedCache;

/**
 * `ContentCache` on the kernel's `VersionedCache` (CONVENTIONS): the cache is in PostgreSQL on the same connection, so
 * the version replaced inside a change commits or rolls back with it. Its version is read on each remember - a shop
 * page asks once - and nothing is memoised past the call (lesson 67), so a change in the same request is seen.
 */
final readonly class VersionedContentCache implements ContentCache
{
    /** A safety net only: every change replaces the version at once. */
    private const int SNAPSHOT_SECONDS = 3600;

    public const string NAME = 'content:shop';

    private VersionedCache $cache;

    public function __construct(Cache $store, Connection $db)
    {
        $this->cache = new VersionedCache($store, $db, self::NAME, self::SNAPSHOT_SECONDS);
    }

    public function remember(string $part, Closure $load): mixed
    {
        return $this->cache->remember($load, $part);
    }

    public function invalidate(): void
    {
        $this->cache->invalidate();
    }
}
