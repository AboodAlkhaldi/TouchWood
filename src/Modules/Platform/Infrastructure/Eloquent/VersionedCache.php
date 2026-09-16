<?php

declare(strict_types=1);

namespace Modules\Platform\Infrastructure\Eloquent;

use Closure;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A cached snapshot that cannot be overwritten with stale data.
 *
 * Snapshots are stored under the current version. Invalidating replaces the version after the
 * transaction commits, so a reader that loaded old rows just before the commit can only write
 * them under a version nobody reads any more. Deleting a key instead would let that reader put
 * the old snapshot back for good.
 */
final readonly class VersionedCache
{
    /**
     * Old snapshots are orphaned by each invalidation; the TTL only lets them expire.
     */
    private const int SNAPSHOT_SECONDS = 86400;

    public function __construct(
        private Cache $cache,
        private string $name,
    ) {}

    /**
     * @template TSnapshot
     *
     * @param  Closure(): TSnapshot  $load
     * @return TSnapshot
     */
    public function remember(Closure $load): mixed
    {
        return $this->cache->remember("{$this->name}:{$this->version()}", self::SNAPSHOT_SECONDS, $load);
    }

    /**
     * Takes effect once the current transaction commits, or immediately outside one — before
     * any event dispatched after commit, so listeners already see the new data.
     */
    public function invalidate(): void
    {
        DB::afterCommit(fn () => $this->cache->forever($this->versionKey(), (string) Str::ulid()));
    }

    private function version(): string
    {
        $version = $this->cache->get($this->versionKey());

        if (is_string($version)) {
            return $version;
        }

        // add() is atomic: when two readers race here, both end up with the first one's version.
        $this->cache->add($this->versionKey(), (string) Str::ulid());

        return (string) $this->cache->get($this->versionKey());
    }

    private function versionKey(): string
    {
        return "{$this->name}:version";
    }
}
