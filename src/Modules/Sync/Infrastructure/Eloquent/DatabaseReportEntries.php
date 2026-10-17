<?php

declare(strict_types=1);

namespace Modules\Sync\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Sync\Domain\Repository\ReportEntries;
use Modules\Sync\Domain\ValueObject\ReportKind;
use Shared\Domain\ValueObject\StoreId;

/**
 * `sync.report_entries`: one open per store, kind, code and size - its unique partial index,
 * `report_entries_open`, which an entry seen again lands on.
 */
final readonly class DatabaseReportEntries implements ReportEntries
{
    private const string TABLE = 'sync.report_entries';

    /** The provider's code is kept up to the column's length (sync.md §5). */
    private const int MOST_CODE = 255;

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function open(StoreId $store, ReportKind $kind, ?string $code, ?string $variantId, ?string $saleId, array $detail): void
    {
        $this->db->statement(
            'INSERT INTO sync.report_entries (store_id, kind, code, variant_id, sale_id, detail, opened_at, seen_at)'
            .' VALUES (?, ?, ?, ?, ?, ?::jsonb, now(), now())'
            ." ON CONFLICT (store_id, kind, COALESCE(code, ''), COALESCE(variant_id, '')) WHERE closed_at IS NULL"
            .' DO UPDATE SET seen_at = now(), detail = EXCLUDED.detail, sale_id = EXCLUDED.sale_id',
            [$store->value, $kind->value, $code === null ? null : mb_substr($code, 0, self::MOST_CODE), $variantId, $saleId, json_encode((object) $detail, JSON_THROW_ON_ERROR)],
        );
    }

    public function close(StoreId $store, ReportKind $kind, array $codes, array $variantIds, string $closedBy): int
    {
        if ($codes === [] && $variantIds === []) {
            return 0;
        }

        $closed = 0;

        foreach ([['code', $codes], ['variant_id', $variantIds]] as [$column, $values]) {
            foreach (array_chunk(array_values(array_unique($values)), 1000) as $chunk) {
                $closed += $this->db->table(self::TABLE)
                    ->where('store_id', $store->value)
                    ->where('kind', $kind->value)
                    ->whereNull('closed_at')
                    ->whereIn($column, $chunk)
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

    public function closeAll(StoreId $store, string $closedBy): int
    {
        return $this->db->table(self::TABLE)
            ->where('store_id', $store->value)
            ->whereNull('closed_at')
            ->update(['closed_at' => CarbonImmutable::now(), 'closed_by' => $closedBy]);
    }
}
