<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Prices;

use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Pricing\Domain\Repository\RetailPrices;
use Modules\Pricing\Domain\ValueObject\CategoryDiscount;
use Shared\Domain\ValueObject\StoreId;

/**
 * What a category discount reaches (pricing.md §1.5): the products in its category and below it **now**
 * (Catalog's `productIdsInCategory`, any stage), less those it leaves out; and of their sizes, those with
 * a retail price in the store - the only sizes it can price (§1.6 step 1).
 */
final readonly class DiscountCoverage
{
    /** Sizes a read of Catalog, as the candidates are written in chunks. */
    private const int CHUNK = 1000;

    public function __construct(
        private CatalogApi $catalog,
        private RetailPrices $retailPrices,
    ) {}

    /**
     * @return array<string, true> product id => true
     */
    public function products(CategoryDiscount $discount): array
    {
        $products = array_fill_keys($this->catalog->productIdsInCategory($discount->categoryId), true);

        foreach ($discount->excludedProductIds as $productId) {
            unset($products[$productId]);
        }

        return $products;
    }

    /**
     * @param  list<CategoryDiscount>  $discounts
     * @return array<string, string> size id => its product, for the store's priced sizes these reach, in size-id order
     */
    public function pricedSizes(StoreId $store, array $discounts): array
    {
        $products = [];

        foreach ($discounts as $discount) {
            $products += $this->products($discount);
        }

        if ($products === []) {
            return [];
        }

        $sizes = [];

        foreach (array_chunk($this->retailPrices->pricedVariants($store), self::CHUNK) as $chunk) {
            foreach ($this->catalog->variants($chunk) as $variant) {
                if (isset($products[$variant->productId])) {
                    $sizes[$variant->id] = $variant->productId;
                }
            }
        }

        ksort($sizes);

        return $sizes;
    }
}
