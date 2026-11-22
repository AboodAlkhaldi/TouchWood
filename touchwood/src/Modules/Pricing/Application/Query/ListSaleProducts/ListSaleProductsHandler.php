<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\ListSaleProducts;

use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Dto\FoundProductsDto;
use Modules\Pricing\Application\Prices\ReadAccess;
use Modules\Pricing\Domain\Exception\InvalidPricingInput;
use Modules\Pricing\Domain\Exception\StoreOff;
use Modules\Pricing\Domain\Repository\Sales;
use Shared\Application\Unauthorized;

/**
 * **The products with a sale in the store** (`ListSaleProducts`, pricing.md §4.3.3 P2's Product filter),
 * for whoever holds any of Pricing's three jobs there: Pricing names them, Catalog finds and pages them
 * (`searchProducts` with `only`, catalog.md amendment 23(b)). Three queries. A read: nothing written.
 */
final readonly class ListSaleProductsHandler
{
    public function __construct(
        private ReadAccess $access,
        private Sales $sales,
        private CatalogApi $catalog,
    ) {}

    /**
     * @throws Unauthorized|InvalidPricingInput|StoreOff
     */
    public function handle(ListSaleProducts $query): FoundProductsDto
    {
        $store = $this->access->authorize($query->storeId)->storeId();

        return $this->catalog->searchProducts($store, $query->search, false, $this->sales->productIds($store), $query->after, ListSaleProducts::PER_PAGE);
    }
}
