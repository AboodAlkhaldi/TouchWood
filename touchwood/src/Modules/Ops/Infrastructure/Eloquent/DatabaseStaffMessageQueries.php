<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Ops\Application\Query\ListStaffMessages\StaffMessageQueries;
use Modules\Ops\Application\Query\ListStaffMessages\StaffMessageRow;
use Shared\Domain\ValueObject\StoreId;
use stdClass;

/** `StaffMessageQueries` in SQL (ops.md §5: `ops.staff_messages`). */
final readonly class DatabaseStaffMessageQueries implements StaffMessageQueries
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function page(StoreId $store, array $audiences, ?string $afterId, int $limit): array
    {
        $marks = implode(', ', array_fill(0, count($audiences), '?'));
        $which = "store_id = ? AND audience IN ({$marks})";
        $bindings = [$store->value, ...$audiences];
        $after = '';

        if ($afterId !== null) {
            // The cursor is a message this reader may see; anything else ends the list.
            $cursor = $this->db->selectOne("SELECT created_at, id FROM ops.staff_messages WHERE id = ? AND {$which}", [strtolower($afterId), ...$bindings]);

            if (! $cursor instanceof stdClass) {
                return [];
            }

            $after = ' AND (created_at, id) < (?::timestamptz, ?)';
            $bindings = [...$bindings, (string) $cursor->created_at, (string) $cursor->id];
        }

        /** @var list<stdClass> $rows */
        $rows = $this->db->select("SELECT * FROM ops.staff_messages WHERE {$which}{$after} ORDER BY created_at DESC, id DESC LIMIT ?", [...$bindings, $limit]);

        return array_map(self::row(...), $rows);
    }

    private static function row(stdClass $row): StaffMessageRow
    {
        return new StaffMessageRow(
            (string) $row->id,
            (string) $row->store_id,
            (string) $row->sender_id,
            (string) $row->audience,
            $row->customer_id === null ? null : (string) $row->customer_id,
            $row->segment_id === null ? null : (string) $row->segment_id,
            (string) $row->title_ar,
            (string) $row->title_en,
            (string) $row->body_ar,
            (string) $row->body_en,
            (bool) $row->by_bell,
            (bool) $row->by_email,
            (string) $row->status,
            (int) $row->bell_count,
            (int) $row->email_count,
            (int) $row->failed_count,
            self::at($row->created_at),
            $row->finished_at === null ? null : self::at($row->finished_at),
        );
    }

    private static function at(mixed $value): DateTimeImmutable
    {
        return CarbonImmutable::parse((string) $value)->toDateTimeImmutable();
    }
}
