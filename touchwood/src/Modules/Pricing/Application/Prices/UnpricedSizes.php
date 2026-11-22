<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Prices;

use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Dto\FoundVariantsDto;
use Modules\Pricing\Domain\Repository\RetailPrices;
use Shared\Domain\ValueObject\StoreId;

/**
 * **Needs a Price** (pricing.md §1.9, §4.3.3 P5): every size the store has switched on with no retail price
 * there - an archived size, or a size of an archived product, left out: it can't be priced until it is
 * restored (owner, 2026-10-11). One reader for the list and its kept count, so the two never disagree:
 * Pricing names its priced sizes (one query), Catalog pages the rest by its order (`searchVariants` with
 * `except`, two queries).
 */
final readonly class UnpricedSizes
{
    public function __construct(
        private CatalogApi $catalog,
        private RetailPrices $retailPrices,
    ) {}

    public function page(StoreId $store, ?string $search, ?string $after, int $limit): FoundVariantsDto
    {
        return $this->catalog->searchVariants($store, $search, null, $this->retailPrices->pricedVariants($store), after: $after, limit: $limit, notArchived: true);
    }

    public function count(StoreId $store): int
    {
        return $this->page($store, null, null, 1)->total;
    }
}
