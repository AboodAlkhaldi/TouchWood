<?php

declare(strict_types=1);

namespace Modules\Platform\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\Connection;
use Modules\Platform\Application\Settings\SettingValues;
use Modules\Platform\Application\Settings\StoredSetting;
use Shared\Application\ActorContext;
use Shared\Application\ActorType;
use Shared\Infrastructure\Cache\VersionedCache;

/**
 * Every stored setting, loaded in one query and kept in the shared cache until one changes (see
 * VersionedCache). Nothing outlives a request or a job: bound **scoped**, it reads the snapshot
 * once per web request (§9.11, owner 2026-10-08) - the panel's frame asked three settings of the
 * same snapshot, 2 queries each. The system (the console, a queued job) reads the cache every time,
 * as before, and so does the rest of a request that changed a setting.
 *
 * @phpstan-type Snapshot array<string, array{id: int, value: mixed}>
 */
final class DatabaseSettings implements SettingValues
{
    /** Safety net only: every change replaces the version at once. Lifetime set because settings change more often than stores (owner, 2026-09-18). */
    private const int SNAPSHOT_SECONDS = 3600;

    private readonly VersionedCache $cache;

    /** The transaction level the request reads at: 0, or the test's own transaction. */
    private readonly int $level;

    /** @var Snapshot|null what this request read */
    private ?array $snapshot = null;

    /** A setting changed in this request: never answered from memory again. */
    private bool $changed = false;

    public function __construct(
        Cache $cache,
        private readonly Connection $db,
        private readonly ActorContext $actors,
    ) {
        $this->cache = new VersionedCache($cache, $db, 'platform:settings', self::SNAPSHOT_SECONDS);
        $this->level = $db->transactionLevel();
    }

    public function find(string $key, ?string $storeId): ?StoredSetting
    {
        $row = $this->snapshot()[self::slot($key, $storeId)] ?? null;

        return $row === null ? null : new StoredSetting($row['id'], $row['value']);
    }

    public function lockForUpdate(string $key, ?string $storeId): ?StoredSetting
    {
        // A row lock locks nothing while the row does not exist yet, so two first writes would
        // both read the default. A transaction-scoped advisory lock on the slot serialises them.
        $this->db->select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [self::slot($key, $storeId)], useReadPdo: false);

        $row = $this->db->table('platform.settings')
            ->where('key', $key)
            ->where('store_id', $storeId)
            ->lockForUpdate()
            ->first(['id', 'value']);

        return $row === null ? null : new StoredSetting((int) $row->id, $this->decode($row->value));
    }

    public function save(string $key, ?string $storeId, mixed $value, ?string $updatedBy): int
    {
        $row = $this->db->selectOne(
            <<<'SQL'
                INSERT INTO platform.settings (store_id, key, value, updated_by, updated_at)
                VALUES (?, ?, ?::jsonb, ?, ?)
                ON CONFLICT (store_id, key)
                DO UPDATE SET value = EXCLUDED.value, updated_by = EXCLUDED.updated_by, updated_at = EXCLUDED.updated_at
                RETURNING id
                SQL,
            [$storeId, $key, json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), $updatedBy, CarbonImmutable::now()],
            // A write: it must never be sent to a read replica.
            useReadPdo: false,
        );

        return (int) $row->id;
    }

    public function invalidate(): void
    {
        $this->cache->invalidate();
        $this->changed = true;
    }

    /**
     * @return Snapshot
     */
    private function snapshot(): array
    {
        // Never inside a transaction opened after the request began: a check made there, after its
        // locks, must see what another process committed meanwhile (access.md amendment 65).
        if ($this->changed
            || $this->actors->current()->type === ActorType::System
            || $this->db->transactionLevel() !== $this->level) {
            /** @var Snapshot */
            return $this->cache->remember(fn (): array => $this->load());
        }

        /** @var Snapshot */
        return $this->snapshot ??= $this->cache->remember(fn (): array => $this->load());
    }

    /**
     * @return Snapshot
     */
    private function load(): array
    {
        $snapshot = [];

        foreach ($this->db->table('platform.settings')->get(['id', 'store_id', 'key', 'value']) as $row) {
            $storeId = $row->store_id === null ? null : (string) $row->store_id;
            $snapshot[self::slot((string) $row->key, $storeId)] = ['id' => (int) $row->id, 'value' => $this->decode($row->value)];
        }

        return $snapshot;
    }

    private static function slot(string $key, ?string $storeId): string
    {
        return ($storeId ?? 'global').'|'.$key;
    }

    private function decode(mixed $json): mixed
    {
        return json_decode((string) $json, true, flags: JSON_THROW_ON_ERROR);
    }
}
