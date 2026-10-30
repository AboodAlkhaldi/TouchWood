<?php

declare(strict_types=1);

namespace Modules\Shipping\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Shipping\Application\Uncovered\UncoveredCounts;

final readonly class DatabaseUncoveredCounts implements UncoveredCounts
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function of(array $storeIds): array
    {
        if ($storeIds === []) {
            return [];
        }

        $counts = [];

        foreach ($this->db->table('shipping.uncovered_counts')->whereIn('store_id', $storeIds)->get(['store_id', 'count']) as $row) {
            $counts[(string) $row->store_id] = (int) $row->count;
        }

        return $counts;
    }

    public function write(string $storeId, int $count): void
    {
        $this->db->table('shipping.uncovered_counts')->upsert(
            [['store_id' => $storeId, 'count' => $count, 'counted_at' => CarbonImmutable::now()]],
            ['store_id'],
            ['count', 'counted_at'],
        );
    }
}
