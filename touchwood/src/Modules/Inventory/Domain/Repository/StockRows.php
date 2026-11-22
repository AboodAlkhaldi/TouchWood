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

    /**
     * The rows these sizes have, read without a lock - for `stock()`; a size with none is left out.
     *
     * @param  list<string>  $variantIds
     * @return array<string, StockRow>
     */
    public function read(StoreId $store, array $variantIds): array;

    /**
     * Every row the store has, read without a lock, in one query - for the screens' filters and the
     * Low Stock list, which look at all of a store's sizes (§4.3.3).
     *
     * @return array<string, StockRow> variant id => its row
     */
    public function ofStore(StoreId $store): array;

    /**
     * These sizes' rows in each of these stores, read without a lock, in one query - a product's stock
     * in every store at once (§4.3.3 `ViewProductStock`). A size with no row in a store is left out.
     *
     * @param  list<StoreId>  $stores
     * @param  list<string>  $variantIds
     * @return array<string, array<string, StockRow>> store id => variant id => its row
     */
    public function inStores(array $stores, array $variantIds): array;

    /**
     * Holds the pieces if the size has them free, in one conditional update (§1.4): no row changed
     * means not enough - with no lock taken first, no race and no deadlock.
     *
     * @param  bool  $withReturned  a wired store counts its returned extra too (§1.9)
     */
    public function reserve(StoreId $store, string $variantId, int $pieces, bool $withReturned): bool;

    /** Frees held pieces: a cancel, an expiry, a tick, an edit that lowered a line. */
    public function free(StoreId $store, string $variantId, int $pieces): void;

    /** Takes shipped pieces off in stock and off held together (a store with no provider). */
    public function take(StoreId $store, string $variantId, int $pieces): void;

    /** Pieces returned in a wired store, on our side until the provider's number changes (§1.9). */
    public function addReturnedExtra(StoreId $store, string $variantId, string $productId, int $pieces): void;

    /**
     * @return list<string> every size with a row in that store, for the repair
     */
    public function variantIds(StoreId $store): array;

    /** What Catalog was told of the size there, so only a flip is pushed next time (§2.3). */
    public function markPushed(StoreId $store, string $variantId, bool $orderable, bool $endingSoon): void;

    public function setInStock(StoreId $store, string $variantId, int $inStock): void;

    /** The provider's number changed, or the store was unwired: its returned extra is followed or taken in (§1.9). */
    public function setReturnedExtra(StoreId $store, string $variantId, int $returnedExtra): void;

    public function setThreshold(StoreId $store, string $variantId, ?int $threshold): void;

    public function setStockDependent(StoreId $store, string $variantId, bool $stockDependent): void;
}
