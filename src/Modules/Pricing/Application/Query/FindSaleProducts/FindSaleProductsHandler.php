<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\FindSaleProducts;

use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Dto\FoundProductsDto;
use Modules\Pricing\Application\Prices\ReadAccess;
use Modules\Pricing\Application\PricingPermissions;
use Modules\Pricing\Domain\Exception\InvalidPricingInput;
use Modules\Pricing\Domain\Exception\StoreOff;
use Shared\Application\Unauthorized;

/**
 * **The offer dialog's product search** (`FindSaleProducts`, pricing.md §4.3.3 P2; stage 5's screens step
 * 3), for whoever may add an offer in the store (**Edit Prices and Sales**): Catalog finds and pages them
 * (`searchProducts` with `readySwitchedOn`, catalog.md amendment 23(b)). The asking and two queries. A
 * read: nothing written.
 */
final readonly class FindSaleProductsHandler
{
    public function __construct(
        private ReadAccess $access,
        private CatalogApi $catalog,
    ) {}

    /**
     * @throws Unauthorized|InvalidPricingInput|StoreOff
     */
    public function handle(FindSaleProducts $query): FoundProductsDto
    {
        $store = $this->access->authorize($query->storeId, [PricingPermissions::PRICE_EDIT])->storeId();

        return $this->catalog->searchProducts($store, $query->search, true, null, $query->after, FindSaleProducts::PER_PAGE);
    }
}
