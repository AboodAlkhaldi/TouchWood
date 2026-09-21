<?php

declare(strict_types=1);

namespace Shared\Infrastructure\Cache;

use Closure;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;

/**
 * A cached snapshot that can never be served stale. In the kernel because every module that caches
 * follows this rule (owner, 2026-09-19): Platform's stores and settings, Access's permissions.
 *
 * Snapshots are stored under the current version; invalidating replaces the version.
 *
 * - **Cache in PostgreSQL** (the setup for now, owner's decision 2026-09-18): the cache table is on
 *   the same connection, so the new version is written inside the transaction of the change. It
 *   commits with the change or rolls back with it — the cache and the data cannot disagree.
 * - **Cache elsewhere** (Redis, if it is added later): a write there is not part of the transaction,
 *   so the version is replaced only after the commit. A reader that loaded old rows just before the
 *   commit can then only store them under a version nobody reads any more. If Redis comes back,
 *   revisit what happens when a version write is lost during an outage.
 */
final readonly class VersionedCache
{
    private bool $transactional;

    /**
     * @param  int  $snapshotSeconds  how long a snapshot is kept. Only a safety net: a change
     *                                replaces the version immediately.
     */
    public function __construct(
        private Cache $cache,
        private Connection $db,
        private string $name,
        private int $snapshotSeconds,
    ) {
        $store = $cache->getStore();
        $storeConnection = $store instanceof DatabaseStore ? $store->getConnection() : null;

        $this->transactional = $storeConnection instanceof Connection
            && $storeConnection->getName() === $db->getName();
    }

    /**
     * @template TSnapshot
     *
     * @param  Closure(): TSnapshot  $load
     * @return TSnapshot
     */
    public function remember(Closure $load): mixed
    {
        return $this->cache->remember("{$this->name}:{$this->version()}", $this->snapshotSeconds, $load);
    }

    /**
     * Call inside the transaction of the change. The new version becomes visible exactly when
     * the change does, and before any event dispatched after commit reaches its listeners.
     */
    public function invalidate(): void
    {
        if ($this->transactional) {
            $this->replaceVersion();

            return;
        }

        $this->db->afterCommit(fn () => $this->replaceVersion());
    }

    private function replaceVersion(): void
    {
        $this->cache->forever($this->versionKey(), (string) Str::ulid());
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
