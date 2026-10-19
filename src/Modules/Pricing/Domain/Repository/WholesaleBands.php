<?php

declare(strict_types=1);

namespace Modules\Pricing\Domain\Repository;

use Modules\Pricing\Domain\ValueObject\Band;
use Shared\Domain\ValueObject\StoreId;

/**
 * A size's wholesale bands in a store (pricing.md §5 `pricing.wholesale_bands`). Every query names its
 * store.
 */
interface WholesaleBands
{
    /**
     * @param  list<string>  $variantIds
     * @return array<string, list<Band>> variant id => its bands, lowest start first
     */
    public function forVariants(StoreId $store, array $variantIds): array;

    /**
     * @param  list<StoreId>  $stores
     * @param  list<string>  $variantIds
     * @return array<string, array<string, list<Band>>> store id => variant id => its bands, lowest start first
     */
    public function inStores(array $stores, array $variantIds): array;

    /**
     * @return list<string> every size with bands in the store, in size-id order
     */
    public function variantsWithBands(StoreId $store): array;

    /**
     * A size's bands, all at once (§3 `SetWholesalePrices`): none removes them.
     *
     * @param  list<Band>  $bands
     */
    public function replace(StoreId $store, string $variantId, array $bands): void;
}
