<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\FindLeftOutProducts;

use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Dto\FoundProductsDto;
use Modules\Pricing\Application\Prices\ReadAccess;
use Modules\Pricing\Application\PricingPermissions;
use Modules\Pricing\Domain\Exception\CategoryNotUsable;
use Modules\Pricing\Domain\Exception\InvalidPricingInput;
use Modules\Pricing\Domain\Exception\StoreOff;
use Shared\Application\Unauthorized;

/**
 * **The discount dialog's Left Out picker** (`FindLeftOutProducts`, pricing.md §4.3.3 P3, Q10; stage 5's
 * screens step 3), for whoever manages category discounts in the store: Catalog's `productsInCategory`
 * (amendment 23(c)). The asking and two queries. A read: nothing written.
 */
final readonly class FindLeftOutProductsHandler
{
    public function __construct(
        private ReadAccess $access,
        private CatalogApi $catalog,
    ) {}

    /**
     * @throws Unauthorized|InvalidPricingInput|StoreOff|CategoryNotUsable
     */
    public function handle(FindLeftOutProducts $query): FoundProductsDto
    {
        $this->access->authorize($query->storeId, [PricingPermissions::CATEGORY_DISCOUNT_MANAGE]);
        $categoryId = strtolower(trim($query->categoryId));

        // Only a category a discount can be put on: one that exists and is switched on (the review of step 3).
        if (($this->catalog->categoriesWithPaths([$categoryId])[$categoryId] ?? null)?->isActive !== true) {
            throw new CategoryNotUsable;
        }

        return $this->catalog->productsInCategory($categoryId, $query->search, $query->after, FindLeftOutProducts::PER_PAGE);
    }
}
