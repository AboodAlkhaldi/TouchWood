<?php

declare(strict_types=1);

namespace Modules\Catalog\Domain\Repository;

use Modules\Catalog\Domain\Model\StoreListing;

/**
 * Each store's rows of its products (catalog.md §1.3, §5.1). Every read names its store, so a
 * store's rows are never mixed with another's; the few reads across stores say so. Written under
 * the products' lock, which every change to a product or its store rows takes.
 */
interface StoreListingRepository
{
    /** The store's rows for the product, or an unchosen listing when the store has none. */
    public function of(string $storeId, string $productId): StoreListing;

    /**
     * Every store's rows for the product — for what reaches all of them, archiving.
     *
     * @return list<StoreListing>
     */
    public function inEveryStore(string $productId): array;

    /** Writes the rows of a listing the store has chosen, as they now are. */
    public function save(StoreListing $listing): void;

    /**
     * The stores where any variant of the product is switched on.
     *
     * @return list<string> store ids
     */
    public function activeStoresOf(string $productId): array;

    /** Whether any store shows this label on any product. */
    public function anyWithLabel(string $labelId): bool;
}
