<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Prices;

use Carbon\CarbonImmutable;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Dto\ProductDto;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Pricing\Application\PricingSettings;
use Modules\Pricing\Domain\Repository\CategoryDiscounts;
use Modules\Pricing\Domain\Repository\RetailPrices;
use Modules\Pricing\Domain\Repository\Sales;
use Modules\Pricing\Domain\ValueObject\Sale;
use Modules\Pricing\Public\Contracts\StoreWiring;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;

/**
 * The store's discount ceiling against its sales and category discounts (pricing.md §1.12; owner,
 * 2026-10-11/13): which of them, running or scheduled, would go deeper than a ceiling - at today's
 * retail prices, or at retail prices a change is about to set.
 */
final readonly class CeilingCheck
{
    public function __construct(
        private PlatformApi $platform,
        private Sales $sales,
        private RetailPrices $retailPrices,
        private CatalogApi $catalog,
        private CategoryDiscounts $discounts,
        private DiscountCoverage $coverage,
        private StoreWiring $wiring,
    ) {}

    /**
     * Whether a fixed-amount category discount **skips** a size it would take past the ceiling rather
     * than being refused for it: in a wired store, whose prices are the provider's and cannot be
     * changed here (sync.md §9.3 #17, #20; owner, 2026-10-17). Elsewhere staff are refused (§1.12).
     */
    public function skipsPastCeiling(StoreId $store): bool
    {
        return $this->wiring->isWired($store);
    }

    public function ceiling(StoreId $store): int
    {
        return (int) $this->platform->setting(PricingSettings::MAX_DISCOUNT_PERCENT, $store)->value;
    }

    /**
     * @param  array<string, Money>  $retail  retail prices about to be set, by size, in place of today's
     * @return list<Sale> the sales that would go deeper than the ceiling
     */
    public function salesOver(StoreId $store, int $ceilingPercent, array $retail = []): array
    {
        $now = CarbonImmutable::now()->toDateTimeImmutable();
        $sales = $retail === []
            ? $this->sales->notEnded($store, $now)
            : $this->sales->notEndedOn($store, array_map('strval', array_keys($retail)), $now);
        $sizes = [];

        foreach ($sales as $sale) {
            array_push($sizes, ...$sale->variantIds);
        }

        $sizes = array_values(array_unique($sizes));
        $prices = [...$this->retailPrices->forVariants($store, $sizes), ...$retail];
        $over = [];

        foreach ($sales as $sale) {
            foreach ($sale->variantIds as $variantId) {
                if (isset($prices[$variantId]) && $sale->depthOn($prices[$variantId]) > $ceilingPercent * 100) {
                    $over[] = $sale;

                    break;
                }
            }
        }

        return $over;
    }

    /**
     * The category discounts running or scheduled that would go deeper than a ceiling (§1.12): a
     * percentage above it - only a lower ceiling does that, a percentage being as deep on every size; a
     * fixed amount more than it below the retail price of a size it reaches - today's, or one a change is
     * about to set, a size priced for the first time included. A size the amount would take to 0 is
     * skipped by the discount, so never measured.
     *
     * @param  array<string, Money>  $retail  retail prices about to be set, by size, in place of today's
     * @return array<string, array{category: string, products: list<string>}> discount id => its category and the products it takes past
     */
    public function discountsOver(StoreId $store, int $ceilingPercent, array $retail = []): array
    {
        $over = [];
        // A fixed amount in a wired store skips what it would take past the ceiling: never over it.
        $skips = $this->skipsPastCeiling($store);
        $productOf = $retail === [] ? [] : array_map(static fn ($variant): string => $variant->productId, $this->catalog->variants(array_map('strval', array_keys($retail))));

        foreach ($this->discounts->notEnded($store, CarbonImmutable::now()->toDateTimeImmutable()) as $discount) {
            if ($discount->percentBasisPoints !== null) {
                if ($retail === [] && $discount->percentBasisPoints > $ceilingPercent * 100) {
                    $over[$discount->id] = ['category' => $discount->categoryId, 'products' => []];
                }

                continue;
            }

            if ($skips) {
                continue;
            }

            $products = $this->coverage->products($discount);
            $sizes = $retail === []
                ? $this->coverage->pricedSizesOf($store, $products)
                : array_filter($productOf, static fn (string $productId): bool => isset($products[$productId]));
            $prices = $retail === [] ? $this->retailPrices->forVariants($store, array_keys($sizes)) : array_intersect_key($retail, $sizes);
            $hit = [];

            foreach ($prices as $variantId => $price) {
                if ($discount->priceOn($price) !== null && $discount->depthOn($price) > $ceilingPercent * 100) {
                    $hit[] = $sizes[$variantId];
                }
            }

            if ($hit !== []) {
                $over[$discount->id] = ['category' => $discount->categoryId, 'products' => array_values(array_unique($hit))];
            }
        }

        return $over;
    }

    /**
     * Of the prices about to be set, the sizes that take these sales or discounts past the ceiling - named
     * on the refusal, so the screen marks their rows (owner Q2). A discount's sizes are those of the
     * products it takes past it.
     *
     * @param  array<string, Money>  $retail
     * @param  list<Sale>  $sales
     * @param  array<string, array{category: string, products: list<string>}>  $discounts
     * @return list<string>
     */
    public function sizesOver(array $retail, array $sales, array $discounts): array
    {
        $sizes = array_map('strval', array_keys($retail));
        $hit = [];

        foreach ($sales as $sale) {
            array_push($hit, ...array_intersect($sizes, $sale->variantIds));
        }

        $products = array_merge(...array_values(array_map(static fn (array $discount): array => $discount['products'], $discounts)));

        if ($products !== []) {
            foreach ($this->catalog->variants($sizes) as $variant) {
                if (in_array($variant->productId, $products, true)) {
                    $hit[] = $variant->id;
                }
            }
        }

        $hit = array_values(array_unique($hit));
        sort($hit);

        return $hit;
    }

    /**
     * The products of these sales by name, in the language of whoever is refused, for the note: staff
     * see which products hit the ceiling (owner, 2026-10-13). A product with no English name yet shows
     * its Arabic one.
     *
     * @param  list<Sale>  $sales
     */
    public function productNames(array $sales): string
    {
        return $this->namesFor($sales, []);
    }

    /**
     * The note's names for sales and category discounts past the ceiling: each product they take past
     * it; for a percentage discount, which reaches every product in it alike, its category.
     *
     * @param  list<Sale>  $sales
     * @param  array<string, array{category: string, products: list<string>}>  $discounts
     */
    public function namesFor(array $sales, array $discounts): string
    {
        $english = app()->getLocale() === 'en';
        $productIds = array_map(static fn (Sale $sale): string => $sale->productId, $sales);
        $categories = [];

        foreach ($discounts as $discount) {
            array_push($productIds, ...$discount['products']);

            if ($discount['products'] === []) {
                $categories[] = $discount['category'];
            }
        }

        $names = array_map(
            static fn (ProductDto $product): string => $english ? ($product->nameEn ?? $product->nameAr) : $product->nameAr,
            array_values($this->catalog->products(array_values(array_unique($productIds)))),
        );

        foreach (array_values(array_unique($categories)) as $categoryId) {
            $category = $this->catalog->category($categoryId);

            if ($category !== null) {
                $names[] = $english ? $category->nameEn : $category->nameAr;
            }
        }

        return implode($english ? ', ' : '، ', $names);
    }
}
