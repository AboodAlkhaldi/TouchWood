<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Ops\Application\Inbox\InboxQueries;
use Modules\Ops\Application\Inbox\InboxRow;
use Modules\Ops\Domain\ValueObject\InboxOwner;
use Modules\Ops\Public\Enums\MessageKind;
use stdClass;

/**
 * `InboxQueries` in SQL (ops.md §1.2): each bell on its index - a customer's on (customer, store,
 * newest), a staff member's on (staff, newest) - and the unread count on its partial index.
 */
final readonly class DatabaseInboxQueries implements InboxQueries
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function page(InboxOwner $owner, string $locale, ?string $afterId, int $limit): array
    {
        [$mine, $bindings] = DatabaseInbox::owned($owner);
        // The language's own columns, never a name built from the request.
        [$title, $line] = $locale === 'ar' ? ['title_ar', 'line_ar'] : ['title_en', 'line_en'];
        $after = '';

        if ($afterId !== null) {
            // The cursor is one of the owner's own entries; anything else ends the list.
            $cursor = $this->db->selectOne("SELECT created_at, id FROM ops.inbox_entries WHERE id = ? AND {$mine}", [$afterId, ...$bindings]);

            if (! $cursor instanceof stdClass) {
                return [];
            }

            $after = ' AND (created_at, id) < (?::timestamptz, ?)';
            $bindings = [...$bindings, (string) $cursor->created_at, (string) $cursor->id];
        }

        /** @var list<stdClass> $rows */
        $rows = $this->db->select(
            "SELECT id, kind, store_id, {$title} AS title, {$line} AS line, link, read_at, created_at FROM ops.inbox_entries WHERE {$mine}{$after} ORDER BY created_at DESC, id DESC LIMIT ?",
            [...$bindings, $limit],
        );

        return array_map(static fn (stdClass $row): InboxRow => new InboxRow(
            (string) $row->id,
            MessageKind::from((string) $row->kind),
            (string) $row->store_id,
            (string) $row->title,
            (string) $row->line,
            $row->link === null ? null : (string) $row->link,
            $row->read_at !== null,
            CarbonImmutable::parse((string) $row->created_at)->toDateTimeImmutable(),
        ), $rows);
    }

    public function unread(InboxOwner $owner): int
    {
        [$mine, $bindings] = DatabaseInbox::owned($owner);

        return (int) ($this->db->selectOne("SELECT count(*) AS unread FROM ops.inbox_entries WHERE {$mine} AND read_at IS NULL", $bindings)->unread ?? 0);
    }
}
