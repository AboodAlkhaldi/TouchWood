<?php

declare(strict_types=1);

namespace Modules\Content\Infrastructure\Eloquent;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Content\Domain\Repository\UnreadTranslations;

/** `UnreadTranslations` in SQL (`content.unread_translations`): a row is the mark. */
final readonly class DatabaseUnreadTranslations implements UnreadTranslations
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function has(string $kind, string $itemId): bool
    {
        return $this->db->table('content.unread_translations')->where('kind', $kind)->where('item_id', strtolower($itemId))->exists();
    }

    public function marked(string $kind, array $itemIds): array
    {
        if ($itemIds === []) {
            return [];
        }

        $marked = [];

        foreach ($this->db->table('content.unread_translations')->where('kind', $kind)->whereIn('item_id', array_map(strtolower(...), $itemIds))->pluck('item_id') as $id) {
            $marked[] = strtolower((string) $id);
        }

        return $marked;
    }

    public function mark(string $kind, string $itemId, string $storeId, ?string $by, DateTimeImmutable $at): void
    {
        $this->db->insert(
            'INSERT INTO content.unread_translations (kind, item_id, store_id, marked_by, marked_at) VALUES (?, ?, ?, ?, ?)
             ON CONFLICT (kind, item_id) DO UPDATE SET marked_by = EXCLUDED.marked_by, marked_at = EXCLUDED.marked_at',
            [$kind, strtolower($itemId), strtolower($storeId), $by === null ? null : strtolower($by), Rows::at($at)],
        );
    }

    public function clear(string $kind, string $itemId): void
    {
        $this->db->table('content.unread_translations')->where('kind', $kind)->where('item_id', strtolower($itemId))->delete();
    }
}
