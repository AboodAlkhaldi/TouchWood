<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Prices;

use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Pricing\Domain\Repository\RetailPrices;
use Shared\Domain\ValueObject\StoreId;

/**
 * **Needs a Price** (pricing.md §1.9): every size the store has switched on with no retail price there -
 * read live (§6.2), one reader for the list and its count, so the two never disagree.
 */
final readonly class UnpricedSizes
{
    public function __construct(
        private CatalogApi $catalog,
        private RetailPrices $retailPrices,
    ) {}

    /**
     * @return list<string> the sizes, in size-id order
     */
    public function in(StoreId $store): array
    {
        $unpriced = array_values(array_diff($this->catalog->switchedOnVariantIds($store), $this->retailPrices->pricedVariants($store)));
        sort($unpriced);

        return $unpriced;
    }
}
