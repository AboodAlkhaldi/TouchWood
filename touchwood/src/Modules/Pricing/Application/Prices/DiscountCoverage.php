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
        // One query: the category and those below it, joined to their products (the review of step 3).
        $categoryId = strtolower($discount->categoryId);
        $products = array_fill_keys($this->catalog->productIdsInCategories([$categoryId])[$categoryId] ?? [], true);

        foreach ($discount->excludedProductIds as $productId) {
            unset($products[$productId]);
        }

        return $products;
    }

    /**
     * The products each discount reaches - its category's and those below it, less those it leaves out - for
     * many discounts in one read of Catalog (stage 5's screens step 3's review: a preview's other discounts).
     *
     * @param  list<CategoryDiscount>  $discounts
     * @return array<string, array<string, true>> discount id => product id => true
     */
    public function productsOfEach(array $discounts): array
    {
        if ($discounts === []) {
            return [];
        }

        $inCategory = $this->catalog->productIdsInCategories(array_values(array_unique(array_map(static fn (CategoryDiscount $discount): string => strtolower($discount->categoryId), $discounts))));
        $each = [];

        foreach ($discounts as $discount) {
            $products = array_fill_keys($inCategory[strtolower($discount->categoryId)] ?? [], true);

            foreach ($discount->excludedProductIds as $productId) {
                unset($products[$productId]);
            }

            $each[$discount->id] = $products;
        }

        return $each;
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

        return $this->pricedSizesOf($store, $products);
    }

    /**
     * @param  array<string, true>  $products  product id => true
     * @return array<string, string> size id => its product, for the store's priced sizes of these products, in size-id order
     */
    public function pricedSizesOf(StoreId $store, array $products): array
    {
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
