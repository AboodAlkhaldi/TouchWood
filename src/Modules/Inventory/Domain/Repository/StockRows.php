<?php

declare(strict_types=1);

namespace Modules\Inventory\Domain\Repository;

use Modules\Inventory\Domain\ValueObject\StockRow;
use Shared\Domain\ValueObject\StoreId;

/**
 * Each size's stock in a store (inventory.md §1.2, §5 `inventory.stock`). Every query names its store.
 *
 * **The order rows are locked in**: always by size id, ascending - here, and in every other writer to
 * `inventory.stock` (a hold's update included, step 3), so two changes never each hold a row the other
 * waits for. A writer that touches several rows sorts them by size id first.
 */
interface StockRows
{
    /**
     * The sizes' rows, made when missing (in stock 0) and locked for the rest of the change's
     * transaction, by size id, so a hold's update waits for the change rather than slipping under it.
     *
     * @param  array<string, string>  $sizes  variant id => its product id
     * @return array<string, StockRow> variant id => its row
     */
    public function lockFor(StoreId $store, array $sizes): array;

    /**
     * The rows these sizes already have, locked the same way; a size with none is left out and no row
     * is made - for a change that may leave a size as it is.
     *
     * @param  list<string>  $variantIds
     * @return array<string, StockRow> variant id => its row
     */
    public function lockExisting(StoreId $store, array $variantIds): array;

    public function setInStock(StoreId $store, string $variantId, int $inStock): void;

    public function setThreshold(StoreId $store, string $variantId, ?int $threshold): void;

    public function setStockDependent(StoreId $store, string $variantId, bool $stockDependent): void;
}
