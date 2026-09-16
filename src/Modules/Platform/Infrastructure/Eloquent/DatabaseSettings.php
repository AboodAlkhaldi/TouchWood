<?php

namespace Modules\Platform\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\ConnectionInterface;
use Modules\Platform\Application\Settings\SettingValues;
use Modules\Platform\Application\Settings\StoredSetting;

/**
 * Every stored setting, loaded in one query and kept in the shared cache until one changes.
 * Nothing is memoised in the process, for the same reason as the store directory: long-running
 * workers would keep stale values.
 *
 * @phpstan-type Snapshot array<string, array{id: int, value: mixed}>
 */
final readonly class DatabaseSettings implements SettingValues
{
    private const string CACHE_KEY = 'platform:settings:v1';

    public function __construct(
        private Cache $cache,
        private ConnectionInterface $db,
    ) {}

    public function find(string $key, ?string $storeId): ?StoredSetting
    {
        /** @var Snapshot $snapshot */
        $snapshot = $this->cache->rememberForever(self::CACHE_KEY, fn (): array => $this->load());
        $row = $snapshot[self::slot($key, $storeId)] ?? null;

        return $row === null ? null : new StoredSetting($row['id'], $row['value']);
    }

    public function lockForUpdate(string $key, ?string $storeId): ?StoredSetting
    {
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
        );

        return (int) $row->id;
    }

    public function forget(): void
    {
        $this->cache->forget(self::CACHE_KEY);
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
