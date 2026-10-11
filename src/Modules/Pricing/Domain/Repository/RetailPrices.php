<?php

declare(strict_types=1);

namespace Modules\Pricing\Domain\Repository;

use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;

/**
 * Each size's retail price in a store (pricing.md §1.2, §5 `pricing.retail_prices`): one per size per
 * store, above 0, with where it came from (`PriceSource`).
 */
interface RetailPrices
{
    /**
     * @param  list<string>  $variantIds
     * @return array<string, Money> variant id => its retail price there; a size with none is left out
     */
    public function forVariants(StoreId $store, array $variantIds): array;

    public function save(StoreId $store, string $variantId, Money $amount, string $source): void;

    public function remove(StoreId $store, string $variantId): void;

    /**
     * @return list<string> every size priced in that store, for the repair job
     */
    public function pricedVariants(StoreId $store): array;
}
