<?php

declare(strict_types=1);

namespace Modules\Inventory\Domain\Repository;

use Modules\Inventory\Domain\ValueObject\StockRow;
use Shared\Domain\ValueObject\StoreId;

/**
 * Each size's stock in a store (inventory.md §1.2, §5 `inventory.stock`). Every query names its store.
 */
interface StockRows
{
    /**
     * The sizes' rows, made when missing (in stock 0) and locked for the rest of the change's
     * transaction, in one order, so a hold's update waits for the change rather than slipping under it.
     *
     * @param  array<string, string>  $sizes  variant id => its product id
     * @return array<string, StockRow> variant id => its row
     */
    public function lockFor(StoreId $store, array $sizes): array;

    public function setInStock(StoreId $store, string $variantId, int $inStock): void;

    public function setThreshold(StoreId $store, string $variantId, ?int $threshold): void;

    public function setStockDependent(StoreId $store, string $variantId, bool $stockDependent): void;
}
