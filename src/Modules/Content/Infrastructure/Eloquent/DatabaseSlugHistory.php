<?php

declare(strict_types=1);

namespace Modules\Content\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Modules\Content\Domain\Repository\SlugHistory;
use stdClass;

/** `SlugHistory` in SQL (content.md §5: `content.slug_history`). */
final readonly class DatabaseSlugHistory implements SlugHistory
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function holder(string $storeId, string $kind, string $locale, string $slug): ?string
    {
        $row = $this->db->selectOne('SELECT item_id FROM content.slug_history WHERE store_id = ? AND kind = ? AND locale = ? AND slug = ?', [strtolower($storeId), $kind, $locale, $slug]);

        return $row instanceof stdClass ? (string) $row->item_id : null;
    }

    public function record(string $storeId, string $kind, string $itemId, string $locale, string $slug): void
    {
        $this->db->insert(
            'INSERT INTO content.slug_history (id, store_id, kind, item_id, locale, slug, created_at) VALUES (?, ?, ?, ?, ?, ?, ?) ON CONFLICT (store_id, kind, locale, slug) DO NOTHING',
            [strtolower((string) Str::ulid()), strtolower($storeId), $kind, strtolower($itemId), $locale, $slug, Rows::at(CarbonImmutable::now()->toDateTimeImmutable())],
        );
    }

    public function forget(string $kind, string $itemId): void
    {
        $this->db->table('content.slug_history')->where('kind', $kind)->where('item_id', strtolower($itemId))->delete();
    }

    public function slugsOf(string $kind, string $itemId): array
    {
        return array_values(array_map(
            static fn (stdClass $row): string => (string) $row->slug,
            $this->db->select('SELECT slug FROM content.slug_history WHERE kind = ? AND item_id = ? ORDER BY created_at, id', [$kind, strtolower($itemId)]),
        ));
    }
}
