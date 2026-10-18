<?php

declare(strict_types=1);

namespace Modules\Inventory\Infrastructure\Eloquent;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Inventory\Domain\Repository\LowCounts;
use Shared\Domain\ValueObject\StoreId;

/**
 * `inventory.low_counts` (inventory.md §5).
 */
final readonly class DatabaseLowCounts implements LowCounts
{
    private const string TABLE = 'inventory.low_counts';

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function save(StoreId $store, int $low, DateTimeImmutable $countedAt): void
    {
        $this->db->statement(
            'INSERT INTO inventory.low_counts (store_id, low, counted_at) VALUES (?, ?, ?)'
            .' ON CONFLICT (store_id) DO UPDATE SET low = EXCLUDED.low, counted_at = EXCLUDED.counted_at'
            .' WHERE inventory.low_counts.counted_at IS NULL OR inventory.low_counts.counted_at <= EXCLUDED.counted_at',
            [$store->value, $low, $countedAt],
        );
    }

    public function claimRecount(StoreId $store): bool
    {
        // One statement, safe inside any transaction: a row is made, or marked, only when no recount waits.
        return $this->db->select(
            'INSERT INTO inventory.low_counts (store_id, recount_queued) VALUES (?, true)'
            .' ON CONFLICT (store_id) DO UPDATE SET recount_queued = true WHERE inventory.low_counts.recount_queued = false RETURNING store_id',
            [$store->value],
        ) !== [];
    }

    public function recountStarts(StoreId $store): void
    {
        $this->db->table(self::TABLE)->where('store_id', $store->value)->update(['recount_queued' => false]);
    }

    public function allRecountsStart(): void
    {
        $this->db->table(self::TABLE)->where('recount_queued', true)->update(['recount_queued' => false]);
    }

    public function of(array $stores): array
    {
        if ($stores === []) {
            return [];
        }

        $counts = [];

        foreach ($this->db->table(self::TABLE)->whereIn('store_id', array_map(static fn (StoreId $store): string => $store->value, $stores))->whereNotNull('low')->get(['store_id', 'low']) as $row) {
            $counts[(string) $row->store_id] = (int) $row->low;
        }

        return $counts;
    }
}
