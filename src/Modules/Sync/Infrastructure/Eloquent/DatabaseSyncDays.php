<?php

declare(strict_types=1);

namespace Modules\Sync\Infrastructure\Eloquent;

use Illuminate\Database\ConnectionInterface;
use JsonException;
use Modules\Sync\Application\Report\SyncDays;
use stdClass;

/**
 * Sync's log counted over day windows (sync.md amendment 1). The windows travel as one jsonb list, so a
 * query keeps its one binding however many stores and days it covers; each row of the log is matched to
 * its store's window by the moment it finished (a read) or opened (an entry).
 */
final readonly class DatabaseSyncDays implements SyncDays
{
    private const string WINDOWS = 'jsonb_to_recordset(?::jsonb) AS w(store_id text, day text, from_at timestamptz, to_at timestamptz)';

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function reads(array $windows, array $stoppedCodes): array
    {
        if ($windows === []) {
            return [];
        }

        $stopped = $stoppedCodes === [] ? 'false' : 'r.error IN ('.implode(', ', array_fill(0, count($stoppedCodes), '?')).')';

        /** @var list<stdClass> $rows */
        $rows = $this->db->select(
            'SELECT w.store_id, w.day, r.kind, o.outcome, count(*) AS reads,'
            ." coalesce(sum(CASE WHEN r.state = 'DONE' THEN r.prices_changed"
            ." ELSE (SELECT count(*) FROM sync.read_lines l WHERE l.read_id = r.id AND l.kind = 'PRICE') END), 0) AS prices,"
            ." coalesce(sum(CASE WHEN r.state = 'DONE' THEN r.stock_changed"
            ." ELSE (SELECT count(*) FROM sync.read_lines l WHERE l.read_id = r.id AND l.kind = 'STOCK') END), 0) AS stock,"
            // As a finished read counts them (DatabaseReadReports::skipped): each unknown code, each size skipped.
            ." coalesce(sum(CASE WHEN r.state = 'DONE' THEN r.skipped ELSE (SELECT count(*) FILTER (WHERE p.kind = 'UNKNOWN_CODE')"
            ." + count(DISTINCT p.variant_id) FILTER (WHERE p.kind IN ('ARCHIVED_SKIPPED', 'BAD_VALUE')) FROM sync.read_reports p WHERE p.read_id = r.id) END), 0) AS skipped"
            .' FROM '.self::WINDOWS
            ." JOIN sync.reads r ON r.store_id = w.store_id AND r.state IN ('DONE', 'FAILED')"
            .' AND r.finished_at >= w.from_at AND r.finished_at < w.to_at'
            ." CROSS JOIN LATERAL (SELECT CASE WHEN r.state = 'DONE' THEN 'DONE' WHEN {$stopped} THEN 'STOPPED' ELSE 'FAILED' END AS outcome) o"
            .' GROUP BY w.store_id, w.day, r.kind, o.outcome',
            [self::json($windows), ...$stoppedCodes],
        );

        return array_map(static fn (stdClass $row): array => [
            'storeId' => (string) $row->store_id,
            'day' => (string) $row->day,
            'kind' => (string) $row->kind,
            'outcome' => (string) $row->outcome,
            'reads' => (int) $row->reads,
            'prices' => (int) $row->prices,
            'stock' => (int) $row->stock,
            'skipped' => (int) $row->skipped,
        ], $rows);
    }

    public function problemsOpened(array $windows): array
    {
        if ($windows === []) {
            return [];
        }

        /** @var list<stdClass> $rows */
        $rows = $this->db->select(
            'SELECT w.store_id, w.day, e.kind, count(*) AS opened'
            .' FROM '.self::WINDOWS
            .' JOIN sync.report_entries e ON e.store_id = w.store_id AND e.opened_at >= w.from_at AND e.opened_at < w.to_at'
            .' GROUP BY w.store_id, w.day, e.kind',
            [self::json($windows)],
        );

        return array_map(static fn (stdClass $row): array => [
            'storeId' => (string) $row->store_id,
            'day' => (string) $row->day,
            'kind' => (string) $row->kind,
            'opened' => (int) $row->opened,
        ], $rows);
    }

    /**
     * @param  list<array{storeId: string, day: string, from: string, to: string}>  $windows
     *
     * @throws JsonException
     */
    private static function json(array $windows): string
    {
        return json_encode(array_map(static fn (array $w): array => [
            'store_id' => $w['storeId'],
            'day' => $w['day'],
            'from_at' => $w['from'],
            'to_at' => $w['to'],
        ], $windows), JSON_THROW_ON_ERROR);
    }
}
