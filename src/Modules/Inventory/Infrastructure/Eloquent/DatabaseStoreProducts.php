<?php

declare(strict_types=1);

namespace Modules\Inventory\Infrastructure\Eloquent;

use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Modules\Inventory\Domain\Repository\StoreProducts;
use Shared\Domain\ValueObject\StoreId;

/**
 * `inventory.store_products` (inventory.md §5): every query names its store.
 */
final readonly class DatabaseStoreProducts implements StoreProducts
{
    private const string TABLE = 'inventory.store_products';

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function endingSoon(StoreId $store, string $productId): bool
    {
        return (bool) $this->db->table(self::TABLE)->where('store_id', $store->value)->where('product_id', $productId)->value('ending_soon');
    }

    public function endingSoonOf(StoreId $store, array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return array_values(array_map('strval', $this->db->table(self::TABLE)->where('store_id', $store->value)->whereIn('product_id', $productIds)->where('ending_soon', true)->pluck('product_id')->all()));
    }

    public function endingSoonIn(array $stores, string $productId): array
    {
        $storeIds = IdArray::valid(array_map(static fn (StoreId $store): string => $store->value, $stores));

        if ($storeIds === []) {
            return [];
        }

        return array_values(array_map('strval', $this->db->table(self::TABLE)
            ->whereRaw('store_id = ANY(?::text[])', [IdArray::of($storeIds)])
            ->where('product_id', strtolower(trim($productId)))
            ->where('ending_soon', true)
            ->pluck('store_id')
            ->all()));
    }

    public function setEndingSoon(StoreId $store, string $productId, bool $endingSoon): void
    {
        $this->db->table(self::TABLE)->upsert([[
            'store_id' => $store->value,
            'product_id' => $productId,
            'ending_soon' => $endingSoon,
            'updated_at' => CarbonImmutable::now(),
        ]], ['store_id', 'product_id'], ['ending_soon', 'updated_at']);
    }
}
