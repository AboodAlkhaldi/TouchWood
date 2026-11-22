<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\Eloquent;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Ops\Domain\Model\InboxEntry;
use Modules\Ops\Domain\Repository\Inbox;
use Modules\Ops\Domain\ValueObject\InboxOwner;

/** `Inbox` in SQL (ops.md §5: `ops.inbox_entries`). Every change names the owner and their stores. */
final readonly class DatabaseInbox implements Inbox
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function add(InboxEntry $entry): void
    {
        $this->db->table('ops.inbox_entries')->insert([
            'id' => $entry->id,
            'customer_id' => $entry->customerId,
            'staff_id' => $entry->staffId,
            'store_id' => $entry->store->value,
            'kind' => $entry->kind->value,
            'title_ar' => $entry->titleAr,
            'title_en' => $entry->titleEn,
            'line_ar' => $entry->lineAr,
            'line_en' => $entry->lineEn,
            'link' => $entry->link,
            'read_at' => null,
            'created_at' => Moment::of($entry->createdAt),
        ]);
    }

    public function markRead(InboxOwner $owner, string $entryId, DateTimeImmutable $at): bool
    {
        [$mine, $bindings] = self::owned($owner);

        if ($this->db->selectOne("SELECT 1 AS found FROM ops.inbox_entries WHERE id = ? AND {$mine}", [$entryId, ...$bindings]) === null) {
            return false;
        }

        // GREATEST: a clock that steps back never writes a read before the entry was made.
        $this->db->update(
            "UPDATE ops.inbox_entries SET read_at = GREATEST(created_at, ?::timestamptz) WHERE id = ? AND {$mine} AND read_at IS NULL",
            [Moment::of($at), $entryId, ...$bindings],
        );

        return true;
    }

    public function markAllRead(InboxOwner $owner, DateTimeImmutable $at): int
    {
        [$mine, $bindings] = self::owned($owner);

        return $this->db->update(
            "UPDATE ops.inbox_entries SET read_at = GREATEST(created_at, ?::timestamptz) WHERE {$mine} AND read_at IS NULL",
            [Moment::of($at), ...$bindings],
        );
    }

    /**
     * The owner's own entries, in their stores - nothing else is ever read or changed: the SQL condition
     * and its bindings. No stores, no entries.
     *
     * @return array{0: string, 1: list<string>}
     */
    public static function owned(InboxOwner $owner): array
    {
        $stores = $owner->storeIds();
        $person = $owner->customerId !== null ? 'customer_id = ?' : 'staff_id = ?';
        $inStores = $stores === [] ? 'FALSE' : 'store_id IN ('.implode(', ', array_fill(0, count($stores), '?')).')';

        return ["{$person} AND {$inStores}", [(string) ($owner->customerId ?? $owner->staffId), ...$stores]];
    }
}
