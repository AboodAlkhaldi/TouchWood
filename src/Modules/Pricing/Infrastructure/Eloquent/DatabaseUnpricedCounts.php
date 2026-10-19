<?php

declare(strict_types=1);

namespace Modules\Pricing\Infrastructure\Eloquent;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Pricing\Domain\Repository\UnpricedCounts;
use Shared\Domain\ValueObject\StoreId;

/**
 * `pricing.unpriced_counts` (pricing.md §5), as Inventory's `low_counts`.
 */
final readonly class DatabaseUnpricedCounts implements UnpricedCounts
{
    private const string TABLE = 'pricing.unpriced_counts';

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function save(StoreId $store, int $unpriced, DateTimeImmutable $countedAt): void
    {
        $this->db->statement(
            'INSERT INTO pricing.unpriced_counts (store_id, unpriced, counted_at) VALUES (?, ?, ?)'
            .' ON CONFLICT (store_id) DO UPDATE SET unpriced = EXCLUDED.unpriced, counted_at = EXCLUDED.counted_at'
            .' WHERE pricing.unpriced_counts.counted_at IS NULL OR pricing.unpriced_counts.counted_at <= EXCLUDED.counted_at',
            [$store->value, $unpriced, $countedAt],
        );
    }

    public function claimRecount(StoreId $store): bool
    {
        // One statement, safe inside any transaction: a row is made, or marked, only when no recount waits.
        return $this->db->select(
            'INSERT INTO pricing.unpriced_counts (store_id, recount_queued) VALUES (?, true)'
            .' ON CONFLICT (store_id) DO UPDATE SET recount_queued = true WHERE pricing.unpriced_counts.recount_queued = false RETURNING store_id',
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

        foreach ($this->db->table(self::TABLE)->whereIn('store_id', array_map(static fn (StoreId $store): string => $store->value, $stores))->whereNotNull('unpriced')->get(['store_id', 'unpriced']) as $row) {
            $counts[(string) $row->store_id] = (int) $row->unpriced;
        }

        return $counts;
    }
}
