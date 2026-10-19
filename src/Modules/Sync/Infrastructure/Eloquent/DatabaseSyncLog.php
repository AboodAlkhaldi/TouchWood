<?php

declare(strict_types=1);

namespace Modules\Sync\Infrastructure\Eloquent;

use Illuminate\Database\ConnectionInterface;
use Modules\Sync\Application\Report\SyncLog;
use Shared\Domain\ValueObject\StoreId;
use stdClass;

/**
 * The report's and the reads' rows, straight from `sync.*` - one query a page, one a count. Keyset pages
 * compare the row's key as a whole (`(seen_at, id) < (?, ?)`), so a row is never shown twice or skipped
 * however many come in between.
 */
final readonly class DatabaseSyncLog implements SyncLog
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function openEntries(StoreId $store, ?string $kind, ?array $after, int $limit): array
    {
        $rows = $this->db->table('sync.report_entries')->where('store_id', $store->value)->whereNull('closed_at')
            ->when($kind !== null, static fn ($query) => $query->where('kind', $kind))
            ->when($after !== null, static fn ($query) => $query->whereRaw('(seen_at, id) < (?::timestamptz, ?::bigint)', $after))
            ->orderByDesc('seen_at')->orderByDesc('id')->limit($limit)->get()->all();

        return array_values(array_map(self::entry(...), $rows));
    }

    public function openByKind(StoreId $store): array
    {
        $counts = [];

        foreach ($this->db->table('sync.report_entries')->where('store_id', $store->value)->whereNull('closed_at')->groupBy('kind')->selectRaw('kind, count(*) AS open')->get() as $row) {
            $counts[(string) $row->kind] = (int) $row->open;
        }

        return $counts;
    }

    public function openCount(StoreId $store): int
    {
        return $this->db->table('sync.report_entries')->where('store_id', $store->value)->whereNull('closed_at')->count();
    }

    public function openCountIn(?array $stores): int
    {
        if ($stores === []) {
            return 0;
        }

        return $this->db->table('sync.report_entries')->whereNull('closed_at')
            ->when($stores !== null, static fn ($query) => $query->whereIn('store_id', array_map(static fn (StoreId $store): string => $store->value, $stores ?? [])))
            ->count();
    }

    public function reads(StoreId $store, ?array $after, int $limit): array
    {
        $rows = $this->db->table('sync.reads')->where('store_id', $store->value)
            ->when($after !== null, static fn ($query) => $query->whereRaw('(queued_at, id) < (?::timestamptz, ?)', $after))
            ->orderByDesc('queued_at')->orderByDesc('id')->limit($limit)->get()->all();

        return array_values(array_map(self::readRow(...), $rows));
    }

    public function readsCount(StoreId $store): int
    {
        return $this->db->table('sync.reads')->where('store_id', $store->value)->count();
    }

    public function read(string $readId): ?array
    {
        $id = strtolower(trim($readId));

        // A read's id is a ULID: anything else is no read, never a query the database refuses.
        if (preg_match('/\A[0-9a-hjkmnp-tv-z]{26}\z/', $id) !== 1) {
            return null;
        }

        $row = $this->db->table('sync.reads')->where('id', $id)->first();

        return $row instanceof stdClass ? self::readRow($row) : null;
    }

    public function countsWritten(array $readIds): array
    {
        if ($readIds === []) {
            return [];
        }

        $marks = implode(', ', array_fill(0, count($readIds), '?'));
        $rows = $this->db->select(
            'SELECT r.id,'
            ." (SELECT count(*) FROM sync.read_lines l WHERE l.read_id = r.id AND l.kind = 'PRICE') AS prices,"
            ." (SELECT count(*) FROM sync.read_lines l WHERE l.read_id = r.id AND l.kind = 'STOCK') AS stock,"
            // As a finished read counts them (DatabaseReadReports::skipped): each unknown code, each size skipped.
            ." (SELECT count(*) FILTER (WHERE p.kind = 'UNKNOWN_CODE') + count(DISTINCT p.variant_id) FILTER (WHERE p.kind IN ('ARCHIVED_SKIPPED', 'BAD_VALUE')) FROM sync.read_reports p WHERE p.read_id = r.id) AS skipped"
            ." FROM sync.reads r WHERE r.id IN ({$marks})",
            $readIds,
        );
        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row->id] = ['prices' => (int) $row->prices, 'stock' => (int) $row->stock, 'skipped' => (int) $row->skipped];
        }

        return $counts;
    }

    public function sections(string $readId): array
    {
        $row = $this->db->selectOne(
            "SELECT (SELECT count(*) FROM sync.read_lines WHERE read_id = ? AND kind = 'PRICE') AS prices,"
            ." (SELECT count(*) FROM sync.read_lines WHERE read_id = ? AND kind = 'STOCK') AS stock,"
            .' (SELECT count(*) FROM sync.read_reports WHERE read_id = ?) AS reported',
            [$readId, $readId, $readId],
        );

        return ['prices' => (int) ($row->prices ?? 0), 'stock' => (int) ($row->stock ?? 0), 'reported' => (int) ($row->reported ?? 0)];
    }

    public function lines(string $readId, string $kind, int $page, int $perPage): array
    {
        return array_values(array_map(static fn (stdClass $row): array => [
            'variantId' => (string) $row->variant_id,
            'code' => $row->code === null ? null : (string) $row->code,
            'before' => $row->before === null ? null : (string) $row->before,
            'after' => (string) $row->after,
        ], $this->db->table('sync.read_lines')->where('read_id', $readId)->where('kind', $kind)->orderBy('id')->forPage($page, $perPage)->get()->all()));
    }

    public function reported(string $readId, int $page, int $perPage): array
    {
        return array_values(array_map(static fn (stdClass $row): array => [
            'kind' => (string) $row->kind,
            'code' => $row->code === null ? null : (string) $row->code,
            'variantId' => $row->variant_id === null ? null : (string) $row->variant_id,
            'saleId' => $row->sale_id === null ? null : (string) $row->sale_id,
            'detail' => self::detail($row->detail),
        ], $this->db->table('sync.read_reports')->where('read_id', $readId)->orderBy('kind')->orderBy('id')->forPage($page, $perPage)->get()->all()));
    }

    public function status(StoreId $store): array
    {
        $row = $this->db->selectOne(
            'SELECT c.provider, c.state, c.last_error, c.last_full_read_at,'
            ." (SELECT finished_at FROM sync.reads WHERE store_id = s.id AND state = 'DONE' ORDER BY finished_at DESC LIMIT 1) AS last_read_at,"
            ." (SELECT kind || ' ' || started_at FROM sync.reads WHERE store_id = s.id AND state = 'RUNNING' LIMIT 1) AS running,"
            ." (SELECT kind || ' ' || queued_at FROM sync.reads WHERE store_id = s.id AND state = 'QUEUED' ORDER BY queued_at, id LIMIT 1) AS waiting"
            .' FROM (SELECT ?::text AS id) s LEFT JOIN sync.connections c ON c.store_id = s.id',
            [$store->value],
        );
        $moment = static function (mixed $value): ?array {
            if ($value === null) {
                return null;
            }

            [$kind, $at] = explode(' ', (string) $value, 2);

            return ['kind' => $kind, 'at' => $at];
        };

        return [
            'provider' => $row?->provider === null ? null : (string) $row->provider,
            'state' => $row?->state === null ? null : (string) $row->state,
            'lastError' => $row?->last_error === null ? null : (string) $row->last_error,
            'lastFullReadAt' => $row?->last_full_read_at === null ? null : (string) $row->last_full_read_at,
            'lastReadAt' => $row?->last_read_at === null ? null : (string) $row->last_read_at,
            'running' => $moment($row?->running),
            'waiting' => $moment($row?->waiting),
        ];
    }

    public function nowWaiting(): array
    {
        return array_values(array_map('strval', $this->db->table('sync.reads')->where('state', 'QUEUED')->where('kind', 'NOW')->distinct()->pluck('store_id')->all()));
    }

    /**
     * @return array<string, mixed>
     */
    private static function detail(mixed $detail): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $detail, true, flags: JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * @return array{id: int, kind: string, code: string|null, variantId: string|null, saleId: string|null, detail: array<string, mixed>, openedAt: string, seenAt: string}
     */
    private static function entry(stdClass $row): array
    {
        return [
            'id' => (int) $row->id,
            'kind' => (string) $row->kind,
            'code' => $row->code === null ? null : (string) $row->code,
            'variantId' => $row->variant_id === null ? null : (string) $row->variant_id,
            'saleId' => $row->sale_id === null ? null : (string) $row->sale_id,
            'detail' => self::detail($row->detail),
            'openedAt' => (string) $row->opened_at,
            'seenAt' => (string) $row->seen_at,
        ];
    }

    /**
     * @return array{id: string, storeId: string, kind: string, state: string, codes: list<string>|null, requestedById: string|null, pricesChanged: int, stockChanged: int, skipped: int, error: string|null, queuedAt: string, startedAt: string|null, finishedAt: string|null}
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
