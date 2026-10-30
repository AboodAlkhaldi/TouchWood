<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Ops\Domain\Model\DailyReportEmail;
use Modules\Ops\Domain\Model\DailyReportFile;
use Modules\Ops\Domain\Repository\DailyReports;
use Shared\Domain\ValueObject\StoreId;
use stdClass;

/** `DailyReports` in SQL (ops.md §1.11, §5): `ops.daily_reports` and `ops.daily_report_settings`. */
final readonly class DatabaseDailyReports implements DailyReports
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function add(DailyReportFile $report): void
    {
        $this->db->table('ops.daily_reports')->insert([
            'id' => $report->id,
            'store_id' => $report->store?->value,
            'day' => $report->day,
            'past_day' => $report->pastDay,
            'made_by' => $report->madeBy,
            'made_at' => Moment::of($report->madeAt),
            'locale' => $report->locale,
            'file' => $report->file,
            'bytes' => $report->bytes,
            'emailed_at' => $report->emailedAt === null ? null : Moment::of($report->emailedAt),
        ]);
    }

    public function find(string $id): ?DailyReportFile
    {
        $row = $this->db->selectOne('SELECT * FROM ops.daily_reports WHERE id = ?', [$id]);

        return $row instanceof stdClass ? self::report($row) : null;
    }

    public function page(?array $stores, bool $allStores, ?string $after, int $limit): array
    {
        if ($stores === []) {
            $which = $allStores ? 'store_id IS NULL' : 'false';
            $bindings = [];
        } elseif ($stores === null) {
            $which = $allStores ? 'true' : 'store_id IS NOT NULL';
            $bindings = [];
        } else {
            $marks = implode(', ', array_fill(0, count($stores), '?'));
            $which = '(store_id IN ('.$marks.')'.($allStores ? ' OR store_id IS NULL' : '').')';
            $bindings = array_map(static fn (StoreId $store): string => $store->value, $stores);
        }

        $cursor = '';
        if ($after !== null) {
            // The cursor is a row the reader may see; anything else ends the list.
            $row = $this->db->selectOne("SELECT made_at, id FROM ops.daily_reports WHERE id = ? AND {$which}", [$after, ...$bindings]);
            if (! $row instanceof stdClass) {
                return [];
            }
            $cursor = ' AND (made_at, id) < (?::timestamptz, ?)';
            $bindings = [...$bindings, (string) $row->made_at, (string) $row->id];
        }

        /** @var list<stdClass> $rows */
        $rows = $this->db->select("SELECT * FROM ops.daily_reports WHERE {$which}{$cursor} ORDER BY made_at DESC, id DESC LIMIT ?", [...$bindings, $limit]);

        return array_map(self::report(...), $rows);
    }

    public function markEmailed(string $id, DateTimeImmutable $at): void
    {
        // GREATEST: a clock that steps back never writes a send before the report was made.
        $this->db->update('UPDATE ops.daily_reports SET emailed_at = GREATEST(made_at, ?::timestamptz) WHERE id = ?', [Moment::of($at), $id]);
    }

    public function prune(DateTimeImmutable $before): array
    {
        /** @var list<stdClass> $rows */
        $rows = $this->db->select('DELETE FROM ops.daily_reports WHERE made_at < ?::timestamptz RETURNING file', [Moment::of($before)]);

        return array_map(static fn (stdClass $row): string => (string) $row->file, $rows);
    }

    public function email(string $staffId, ?StoreId $store): ?DailyReportEmail
    {
        $row = $store === null
            ? $this->db->selectOne('SELECT * FROM ops.daily_report_settings WHERE staff_id = ? AND store_id IS NULL', [$staffId])
            : $this->db->selectOne('SELECT * FROM ops.daily_report_settings WHERE staff_id = ? AND store_id = ?', [$staffId, $store->value]);

        return $row instanceof stdClass ? self::setting($row) : null;
    }

    public function emailById(string $id): ?DailyReportEmail
    {
        $row = $this->db->selectOne('SELECT * FROM ops.daily_report_settings WHERE id = ?', [$id]);

        return $row instanceof stdClass ? self::setting($row) : null;
    }

    public function emailsOf(string $staffId): array
    {
        /** @var list<stdClass> $rows */
        $rows = $this->db->select('SELECT * FROM ops.daily_report_settings WHERE staff_id = ? ORDER BY store_id NULLS FIRST', [$staffId]);

        return array_map(self::setting(...), $rows);
    }

    public function setEmail(string $newId, string $staffId, ?StoreId $store, bool $isOn, string $sendAt, ?DateTimeImmutable $nextDueAt, DateTimeImmutable $now): DailyReportEmail
    {
        // One statement: two submits at once both land, one after the other, each a version of its own.
        $row = $this->db->selectOne(<<<'SQL'
            INSERT INTO ops.daily_report_settings AS s (id, staff_id, store_id, is_on, send_at, version, next_due_at, updated_at)
            VALUES (?, ?, ?, ?, ?, 1, ?::timestamptz, ?::timestamptz)
            ON CONFLICT (staff_id, store_id) DO UPDATE SET
                is_on = EXCLUDED.is_on, send_at = EXCLUDED.send_at, version = s.version + 1,
                next_due_at = EXCLUDED.next_due_at, updated_at = EXCLUDED.updated_at
            RETURNING *
            SQL, [$newId, $staffId, $store?->value, $isOn, $sendAt, $nextDueAt === null ? null : Moment::of($nextDueAt), Moment::of($now)]);

        return self::setting($row instanceof stdClass ? $row : throw new \LogicException('The upsert returned no row.'));
    }

    public function advanceEmail(string $id, int $version, DateTimeImmutable $due, DateTimeImmutable $next): bool
    {
        return $this->db->update(
            'UPDATE ops.daily_report_settings SET next_due_at = ?::timestamptz WHERE id = ? AND version = ? AND is_on AND next_due_at = ?::timestamptz',
            [Moment::of($next), $id, $version, Moment::of($due)],
        ) === 1;
    }

    public function switchEmailOff(string $id, int $version, DateTimeImmutable $now): void
    {
        $this->db->update(
            'UPDATE ops.daily_report_settings SET is_on = false, next_due_at = NULL, version = version + 1, updated_at = ?::timestamptz WHERE id = ? AND version = ?',
            [Moment::of($now), $id, $version],
        );
    }

    private static function report(stdClass $row): DailyReportFile
    {
        return new DailyReportFile(
            (string) $row->id,
            $row->store_id === null ? null : StoreId::fromString((string) $row->store_id),
            (string) $row->day,
            (bool) $row->past_day,
            (string) $row->made_by,
            CarbonImmutable::parse((string) $row->made_at)->toDateTimeImmutable(),
            (string) $row->locale,
            (string) $row->file,
            (int) $row->bytes,
            $row->emailed_at === null ? null : CarbonImmutable::parse((string) $row->emailed_at)->toDateTimeImmutable(),
        );
    }

    private static function setting(stdClass $row): DailyReportEmail
    {
        return new DailyReportEmail(
            (string) $row->id,
            (string) $row->staff_id,
            $row->store_id === null ? null : StoreId::fromString((string) $row->store_id),
            (bool) $row->is_on,
            (string) $row->send_at,
            (int) $row->version,
            CarbonImmutable::parse((string) $row->updated_at)->toDateTimeImmutable(),
            $row->next_due_at === null ? null : CarbonImmutable::parse((string) $row->next_due_at)->toDateTimeImmutable(),
        );
    }
}
