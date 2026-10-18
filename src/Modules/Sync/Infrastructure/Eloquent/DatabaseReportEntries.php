<?php

declare(strict_types=1);

namespace Modules\Sync\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Sync\Domain\Repository\ReportEntries;
use Modules\Sync\Domain\ValueObject\ReportKind;
use Shared\Domain\ValueObject\StoreId;

/**
 * `sync.report_entries`: one open per store, kind, code and size - its unique partial index,
 * `report_entries_open`, which an entry seen again lands on. Times are the app's clock.
 */
final readonly class DatabaseReportEntries implements ReportEntries
{
    private const string TABLE = 'sync.report_entries';

    /** The provider's code is kept up to the column's length (sync.md §5). */
    private const int MOST_CODE = 255;

    /** Rows a statement: 7 values each, well under PostgreSQL's bind limit. */
    private const int CHUNK = 500;

    private const int PRUNE_BATCH = 5000;

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function open(StoreId $store, ReportKind $kind, array $entries): void
    {
        // The same thing twice in one batch is one entry: the statement may not touch a row twice.
        $unique = [];

        foreach ($entries as $entry) {
            $code = $entry['code'] === null ? null : mb_substr($entry['code'], 0, self::MOST_CODE);
            $unique[($code ?? '').'|'.($entry['variantId'] ?? '')] = [...$entry, 'code' => $code];
        }

        $now = CarbonImmutable::now();

        foreach (array_chunk(array_values($unique), self::CHUNK) as $chunk) {
            $values = $bindings = [];

            foreach ($chunk as $entry) {
                $values[] = '(?, ?, ?, ?, ?, ?::jsonb, ?, ?)';
                array_push($bindings, $store->value, $kind->value, $entry['code'], $entry['variantId'], $entry['saleId'], json_encode((object) $entry['detail'], JSON_THROW_ON_ERROR), $now, $now);
            }

            $this->db->statement(
                'INSERT INTO sync.report_entries (store_id, kind, code, variant_id, sale_id, detail, opened_at, seen_at) VALUES '.implode(', ', $values)
                ." ON CONFLICT (store_id, kind, COALESCE(code, ''), COALESCE(variant_id, '')) WHERE closed_at IS NULL"
                .' DO UPDATE SET seen_at = EXCLUDED.seen_at, detail = EXCLUDED.detail, sale_id = EXCLUDED.sale_id,'
                // Another sale stopped, or another discount skipped, on the size: told again (§9.3 #18).
                ." announced_at = CASE WHEN sync.report_entries.sale_id IS DISTINCT FROM EXCLUDED.sale_id OR sync.report_entries.detail->'discount' IS DISTINCT FROM EXCLUDED.detail->'discount' THEN NULL ELSE sync.report_entries.announced_at END",
                $bindings,
            );
        }
    }

    public function close(StoreId $store, ReportKind $kind, array $codes, array $variantIds, string $closedBy): int
    {
        $closed = 0;

        // Written as the open-entries index is (`report_entries_open`), so it is used past store and kind.
        foreach ([["COALESCE(code, '')", "COALESCE(variant_id, '') = ''", $codes], ["COALESCE(variant_id, '')", "COALESCE(code, '') = ''", $variantIds]] as [$column, $other, $values]) {
            foreach (array_chunk(array_values(array_unique($values)), 1000) as $chunk) {
                $closed += $this->db->table(self::TABLE)
                    ->where('store_id', $store->value)
                    ->where('kind', $kind->value)
                    ->whereNull('closed_at')
                    ->whereRaw($other)
                    ->whereRaw($column.' IN ('.implode(', ', array_fill(0, count($chunk), '?')).')', $chunk)
                    ->update(['closed_at' => CarbonImmutable::now(), 'closed_by' => $closedBy]);
            }
        }

        return $closed;
    }

    public function closeKind(StoreId $store, ReportKind $kind, string $closedBy): int
    {
        return $this->db->table(self::TABLE)
            ->where('store_id', $store->value)
            ->where('kind', $kind->value)
            ->whereNull('closed_at')
            ->update(['closed_at' => CarbonImmutable::now(), 'closed_by' => $closedBy]);
    }

    public function findOpen(int $entryId): ?array
    {
        $row = $this->db->table(self::TABLE)->where('id', $entryId)->whereNull('closed_at')->first(['store_id', 'kind', 'code', 'variant_id']);

        return $row === null ? null : [
            'storeId' => (string) $row->store_id,
            'kind' => ReportKind::from((string) $row->kind),
            'code' => $row->code === null ? null : (string) $row->code,
            'variantId' => $row->variant_id === null ? null : (string) $row->variant_id,
        ];
    }

    public function closeOne(int $entryId, string $closedBy): bool
    {
        return $this->db->table(self::TABLE)->where('id', $entryId)->whereNull('closed_at')
            ->update(['closed_at' => CarbonImmutable::now(), 'closed_by' => $closedBy]) === 1;
    }

    public function pruneClosedBefore(DateTimeImmutable $before): int
    {
        $deleted = 0;

        // In batches: a long-closed backlog never holds the table in one statement.
        do {
            $batch = $this->db->delete(
                'DELETE FROM sync.report_entries WHERE id IN (SELECT id FROM sync.report_entries WHERE closed_at IS NOT NULL AND closed_at < ? LIMIT ?)',
                [$before, self::PRUNE_BATCH],
            );
            $deleted += $batch;
        } while ($batch === self::PRUNE_BATCH);

        return $deleted;
    }

    public function closeSwitchedOn(StoreId $store, array $variantIds): int
    {
        return $this->close($store, ReportKind::OffOrDraftPriced, [], $variantIds, 'CATALOG');
    }

    public function announce(StoreId $store, array $kinds): array
    {
        $kinds = array_map(static fn (ReportKind $kind): string => $kind->value, $kinds);
        $rows = $this->db->select(
            'UPDATE sync.report_entries SET announced_at = ? WHERE store_id = ? AND closed_at IS NULL AND announced_at IS NULL AND kind IN ('
            .implode(', ', array_fill(0, count($kinds), '?')).') RETURNING kind',
            [CarbonImmutable::now(), $store->value, ...$kinds],
        );
        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row->kind] = ($counts[(string) $row->kind] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }

    public function closeAll(StoreId $store, string $closedBy): int
    {
        return $this->db->table(self::TABLE)
            ->where('store_id', $store->value)
            ->whereNull('closed_at')
            ->update(['closed_at' => CarbonImmutable::now(), 'closed_by' => $closedBy]);
    }
}
