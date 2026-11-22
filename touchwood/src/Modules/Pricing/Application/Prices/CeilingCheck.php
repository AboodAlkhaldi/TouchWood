<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Prices;

use Carbon\CarbonImmutable;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Dto\ProductDto;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Pricing\Application\PricingSettings;
use Modules\Pricing\Domain\Repository\Campaigns;
use Modules\Pricing\Domain\Repository\CategoryDiscounts;
use Modules\Pricing\Domain\Repository\RetailPrices;
use Modules\Pricing\Domain\Repository\Sales;
use Modules\Pricing\Domain\ValueObject\Campaign;
use Modules\Pricing\Domain\ValueObject\CampaignTarget;
use Modules\Pricing\Domain\ValueObject\Sale;
use Modules\Pricing\Public\Contracts\StoreWiring;
use Modules\Pricing\Public\Enums\CampaignTargetKind;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;

/**
 * The store's discount ceiling against its sales, category discounts and campaign runs (pricing.md
 * §1.12, §9.6 #4; owner, 2026-10-11/13): which of them, running or scheduled, would go deeper than a
 * ceiling - at today's retail prices, or at retail prices a change is about to set.
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
        private Campaigns $campaigns,
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
     * The campaign runs scheduled or live that would go deeper than a ceiling (§9.6 #4; content.md Q2 (a)):
     * a percentage above it - only a lower ceiling does that; a fixed price on a size more than it below the
     * size's retail price - today's, or one a change is about to set. In a wired store a fixed price skips
     * what it would take past the ceiling (§9.6 #5): never over it there; a percentage still is.
     *
     * @param  array<string, Money>  $retail  retail prices about to be set, by size, in place of today's
     * @return array<string, array{sizes: list<string>, targets: list<CampaignTarget>}> run id => the sizes a fixed price takes past it, and the percentages above it
     */
    public function campaignsOver(StoreId $store, int $ceilingPercent, array $retail = []): array
    {
        $campaigns = $this->campaigns->notEnded($store, CarbonImmutable::now()->toDateTimeImmutable());

        return $campaigns === [] ? [] : $this->over($store, $campaigns, $ceilingPercent, $retail);
    }

    /**
     * One run's prices about to be saved, against the ceiling at today's retail prices (§9.6 #4).
     *
     * @return array{sizes: list<string>, targets: list<CampaignTarget>}|null
     */
    public function campaignsOverOf(StoreId $store, Campaign $campaign, int $ceilingPercent): ?array
    {
        return $this->over($store, [$campaign], $ceilingPercent, [])[$campaign->runId] ?? null;
    }

    /**
     * @param  non-empty-list<Campaign>  $campaigns
     * @param  array<string, Money>  $retail
     * @return array<string, array{sizes: list<string>, targets: list<CampaignTarget>}>
     */
    private function over(StoreId $store, array $campaigns, int $ceilingPercent, array $retail): array
    {
        $skips = $this->skipsPastCeiling($store);
        $fixed = [];

        foreach ($campaigns as $campaign) {
            foreach ($campaign->targets as $target) {
                if ($target->amount !== null && ($retail === [] || isset($retail[$target->id]))) {
                    $fixed[] = $target->id;
                }
            }
        }

        $prices = $skips || $fixed === [] ? [] : [...$this->retailPrices->forVariants($store, array_values(array_unique($fixed))), ...$retail];
        $over = [];

        foreach ($campaigns as $campaign) {
            $sizes = $targets = [];

            foreach ($campaign->targets as $target) {
                if ($target->amount === null) {
                    // A percentage goes as deep on every size: only a lower ceiling takes it past.
                    if ($retail === [] && ($target->percentBasisPoints ?? 0) > $ceilingPercent * 100) {
                        $targets[] = $target;
                    }
                } elseif (isset($prices[$target->id]) && Campaign::depthOn($target, $prices[$target->id]) > $ceilingPercent * 100) {
                    $sizes[] = $target->id;
                }
            }

            if ($sizes !== [] || $targets !== []) {
                $over[$campaign->runId] = ['sizes' => $sizes, 'targets' => $targets];
            }
        }

        return $over;
    }

    /**
     * The note's names for campaign runs past the ceiling: each size's product, and what a percentage too
     * deep was set on - a product, a size's product, a category or a brand - in the reader's language.
     *
     * @param  array<string, array{sizes: list<string>, targets: list<CampaignTarget>}>  $campaigns
     * @return list<string>
     */
    public function campaignNames(array $campaigns): array
    {
        $english = app()->getLocale() === 'en';
        $sizes = $productIds = $categories = $brands = [];

        foreach ($campaigns as $over) {
            array_push($sizes, ...$over['sizes']);

            foreach ($over['targets'] as $target) {
                match ($target->kind) {
                    CampaignTargetKind::Size => $sizes[] = $target->id,
                    CampaignTargetKind::Product => $productIds[] = $target->id,
                    CampaignTargetKind::Category => $categories[] = $target->id,
                    CampaignTargetKind::Brand => $brands[] = $target->id,
                };
            }
        }

        if ($sizes !== []) {
            array_push($productIds, ...array_values(array_map(static fn ($variant): string => $variant->productId, $this->catalog->variants(array_values(array_unique($sizes))))));
        }

        $names = $productIds === [] ? [] : array_map(
            static fn (ProductDto $product): string => $english ? ($product->nameEn ?? $product->nameAr) : $product->nameAr,
            array_values($this->catalog->products(array_values(array_unique($productIds)))),
        );

        foreach (array_values(array_unique($categories)) as $categoryId) {
            $category = $this->catalog->category($categoryId);

            if ($category !== null) {
                $names[] = $english ? $category->nameEn : $category->nameAr;
            }
        }

        foreach ($brands === [] ? [] : $this->catalog->brands(array_values(array_unique($brands))) as $brand) {
            $names[] = $english ? $brand->nameEn : $brand->nameAr;
        }

        return $names;
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
     * @param  array<string, array{sizes: list<string>, targets: list<CampaignTarget>}>  $campaigns  campaign runs past it (§9.6 #4)
     */
    public function namesFor(array $sales, array $discounts, array $campaigns = []): string
    {
        $english = app()->getLocale() === 'en';
        $names = [...$this->offerNames($sales, $discounts), ...($campaigns === [] ? [] : $this->campaignNames($campaigns))];

        return implode($english ? ', ' : '، ', $names);
    }

    /**
     * @param  list<Sale>  $sales
     * @param  array<string, array{category: string, products: list<string>}>  $discounts
     * @return list<string>
     */
    private function offerNames(array $sales, array $discounts): array
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

        return $names;
    }
}
