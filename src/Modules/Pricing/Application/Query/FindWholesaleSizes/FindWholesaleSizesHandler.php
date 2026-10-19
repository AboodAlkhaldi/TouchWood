<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\FindWholesaleSizes;

use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Dto\FoundVariantDto;
use Modules\Pricing\Application\Prices\PriceViews;
use Modules\Pricing\Application\Prices\ReadAccess;
use Modules\Pricing\Application\PricingPermissions;
use Modules\Pricing\Domain\Exception\InvalidPricingInput;
use Modules\Pricing\Domain\Exception\StoreOff;
use Modules\Pricing\Domain\Repository\WholesaleBands;
use Shared\Application\Unauthorized;

/**
 * **Add Wholesale Prices' size picker** (`FindWholesaleSizes`, pricing.md §4.3.3 P4; stage 5's screens step
 * 3), for whoever edits wholesale prices in the store: the sizes it sells wholesale with no bands yet,
 * through Catalog's `searchVariants` (`soldWholesale`, `except` the sizes with bands). The asking and three
 * queries. A read: nothing written.
 */
final readonly class FindWholesaleSizesHandler
{
    public function __construct(
        private ReadAccess $access,
        private CatalogApi $catalog,
        private WholesaleBands $bands,
        private PriceViews $views,
    ) {}

    /**
     * @throws Unauthorized|InvalidPricingInput|StoreOff
     */
    public function handle(FindWholesaleSizes $query): FoundSizes
    {
        $store = $this->access->authorize($query->storeId, [PricingPermissions::WHOLESALE_EDIT])->storeId();
        $found = $this->catalog->searchVariants($store, $query->search, except: $this->bands->variantsWithBands($store), soldWholesale: true, after: $query->after, limit: FindWholesaleSizes::PER_PAGE, notArchived: true);

        return new FoundSizes(array_map(fn (FoundVariantDto $row) => $this->views->size($row), $found->rows), $found->next, $found->total);
    }
}
