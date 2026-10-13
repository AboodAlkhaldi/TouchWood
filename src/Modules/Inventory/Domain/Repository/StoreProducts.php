<?php

declare(strict_types=1);

namespace Modules\Inventory\Domain\Repository;

use Shared\Domain\ValueObject\StoreId;

/**
 * What staff switched on for a product in a store (inventory.md §1.2, §5 `inventory.store_products`):
 * its "last pieces", in a store with no provider.
 */
interface StoreProducts
{
    /** Whether "last pieces" is switched on for the product there; off when it never was. */
    public function endingSoon(StoreId $store, string $productId): bool;

    public function setEndingSoon(StoreId $store, string $productId, bool $endingSoon): void;

    /**
     * @param  list<string>  $productIds
     * @return list<string> those with "last pieces" switched on there
     */
    public function endingSoonOf(StoreId $store, array $productIds): array;
}
