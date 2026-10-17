<?php

declare(strict_types=1);

namespace Modules\Sync\Infrastructure\Eloquent;

use Illuminate\Database\ConnectionInterface;
use Modules\Sync\Application\Report\SyncLog;
use Shared\Domain\ValueObject\StoreId;
use stdClass;

/**
 * The report's and the reads' rows, straight from `sync.*` - one query a page, one a count.
 *
 * @phpstan-import-type EntryRow from SyncLog
 * @phpstan-import-type ReadRow from SyncLog
 * @phpstan-import-type LineRow from SyncLog
 * @phpstan-import-type ReportedRow from SyncLog
 */
final readonly class DatabaseSyncLog implements SyncLog
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function openEntries(StoreId $store, ?string $kind, int $page, int $perPage): array
    {
        $query = $this->db->table('sync.report_entries')->where('store_id', $store->value)->whereNull('closed_at')
            ->when($kind !== null, static fn ($query) => $query->where('kind', $kind));
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('seen_at')->orderByDesc('id')->forPage($page, $perPage)->get()->all();

        return [array_values(array_map(self::entry(...), $rows)), $total];
    }

    public function openCount(StoreId $store): int
    {
        return $this->db->table('sync.report_entries')->where('store_id', $store->value)->whereNull('closed_at')->count();
    }

    public function reads(StoreId $store, int $page, int $perPage): array
    {
        $query = $this->db->table('sync.reads')->where('store_id', $store->value);
        $total = (clone $query)->count();
        $rows = $query->orderByDesc('queued_at')->orderByDesc('id')->forPage($page, $perPage)->get()->all();

        return [array_values(array_map(self::readRow(...), $rows)), $total];
    }

    public function read(string $readId): ?array
    {
        $row = $this->db->table('sync.reads')->where('id', strtolower(trim($readId)))->first();

        return $row instanceof stdClass ? self::readRow($row) : null;
    }

    public function lines(string $readId, string $kind, int $page, int $perPage): array
    {
        $query = $this->db->table('sync.read_lines')->where('read_id', $readId)->where('kind', $kind);
        $total = (clone $query)->count();
        $rows = array_map(static fn (stdClass $row): array => [
            'variantId' => (string) $row->variant_id,
            'code' => $row->code === null ? null : (string) $row->code,
            'before' => $row->before === null ? null : (string) $row->before,
            'after' => (string) $row->after,
        ], $query->orderBy('id')->forPage($page, $perPage)->get()->all());

        return [array_values($rows), $total];
    }

    public function reported(string $readId, int $page, int $perPage): array
    {
        $query = $this->db->table('sync.read_reports')->where('read_id', $readId);
        $total = (clone $query)->count();
        $rows = array_map(static function (stdClass $row): array {
            /** @var array<string, mixed> $detail */
            $detail = json_decode((string) $row->detail, true, flags: JSON_THROW_ON_ERROR);

            return [
                'kind' => (string) $row->kind,
                'code' => $row->code === null ? null : (string) $row->code,
                'variantId' => $row->variant_id === null ? null : (string) $row->variant_id,
                'saleId' => $row->sale_id === null ? null : (string) $row->sale_id,
                'detail' => $detail,
            ];
        }, $query->orderBy('kind')->orderBy('id')->forPage($page, $perPage)->get()->all());

        return [array_values($rows), $total];
    }

    /**
     * @return EntryRow
     */
    private static function entry(stdClass $row): array
    {
        /** @var array<string, mixed> $detail */
        $detail = json_decode((string) $row->detail, true, flags: JSON_THROW_ON_ERROR);

        return [
            'id' => (int) $row->id,
            'kind' => (string) $row->kind,
            'code' => $row->code === null ? null : (string) $row->code,
            'variantId' => $row->variant_id === null ? null : (string) $row->variant_id,
            'saleId' => $row->sale_id === null ? null : (string) $row->sale_id,
            'detail' => $detail,
            'openedAt' => (string) $row->opened_at,
            'seenAt' => (string) $row->seen_at,
        ];
    }

    /**
     * @return ReadRow
     */
    private static function readRow(stdClass $row): array
    {
        /** @var list<string>|null $codes */
        $codes = $row->codes === null ? null : json_decode((string) $row->codes, true, flags: JSON_THROW_ON_ERROR);

        return [
            'id' => (string) $row->id,
            'storeId' => (string) $row->store_id,
            'kind' => (string) $row->kind,
            'state' => (string) $row->state,
            'codes' => $codes,
            'requestedById' => $row->requested_by_id === null ? null : (string) $row->requested_by_id,
            'pricesChanged' => (int) $row->prices_changed,
            'stockChanged' => (int) $row->stock_changed,
            'skipped' => (int) $row->skipped,
            'error' => $row->error === null ? null : (string) $row->error,
            'queuedAt' => (string) $row->queued_at,
            'startedAt' => $row->started_at === null ? null : (string) $row->started_at,
            'finishedAt' => $row->finished_at === null ? null : (string) $row->finished_at,
        ];
    }
}
