<?php

declare(strict_types=1);

namespace Modules\Platform\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\Connection;
use Modules\Platform\Application\Settings\SettingValues;
use Modules\Platform\Application\Settings\StoredSetting;
use Shared\Infrastructure\Cache\VersionedCache;

/**
 * Every stored setting, loaded in one query and kept in the shared cache until one changes (see
 * VersionedCache). Nothing is memoised in the process, for the same reason as the store
 * directory: long-running workers would keep stale values.
 *
 * @phpstan-type Snapshot array<string, array{id: int, value: mixed}>
 */
final readonly class DatabaseSettings implements SettingValues
{
    /** Safety net only: every change replaces the version at once. Lifetime set because settings change more often than stores (owner, 2026-09-18). */
    private const int SNAPSHOT_SECONDS = 3600;

    private VersionedCache $cache;

    public function __construct(
        Cache $cache,
        private Connection $db,
    ) {
        $this->cache = new VersionedCache($cache, $db, 'platform:settings', self::SNAPSHOT_SECONDS);
    }

    public function find(string $key, ?string $storeId): ?StoredSetting
    {
        /** @var Snapshot $snapshot */
        $snapshot = $this->cache->remember(fn (): array => $this->load());
        $row = $snapshot[self::slot($key, $storeId)] ?? null;

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
