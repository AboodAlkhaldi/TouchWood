<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Prices;

use Carbon\CarbonImmutable;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Dto\ProductDto;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Pricing\Domain\Repository\Campaigns;
use Modules\Pricing\Domain\Repository\CategoryDiscounts;
use Modules\Pricing\Domain\Repository\RetailPrices;
use Modules\Pricing\Domain\Repository\Sales;
use Modules\Pricing\Domain\ValueObject\Campaign;
use Modules\Pricing\Domain\ValueObject\CategoryDiscount;
use Modules\Pricing\Public\Dto\CampaignOfferDto;
use Modules\Pricing\Public\Dto\CampaignPreviewDto;
use Modules\Pricing\Public\Dto\CampaignPreviewProductDto;
use Modules\Pricing\Public\Dto\CampaignPreviewSizeDto;
use Modules\Pricing\Public\Enums\CampaignTargetKind;
use Modules\Pricing\Public\Enums\PriceKind;
use Shared\Domain\ValueObject\Money;

/**
 * **A campaign run's preview** (pricing.md §9.6 #8; content.md §1.7 #6): totals over every product it
 * reaches in the store, then the products 20 at a time by name, each size's retail price, its new price
 * and depth, or why it is skipped - archived, no price, a percentage leaving 0, a fixed price not below
 * the retail price, past the ceiling; and the other offers on those sizes in its dates - sales, category
 * discounts, other runs. Writes nothing.
 *
 * Its reads follow what it reaches: its categories' and brands' products, their sizes and names, then a
 * read of Catalog's facts and of retail prices per chunk of sizes; the other offers a few reads more.
 */
final readonly class CampaignPreviewer
{
    /** Products a page of the preview, as a discount's (§4.3.3 P3). */
    public const int PER_PAGE = 20;

    /** Ids a read of Catalog's facts (bound one by one there). */
    private const int CHUNK = 500;

    public function __construct(
        private CampaignCoverage $coverage,
        private RetailPrices $retailPrices,
        private CatalogApi $catalog,
        private Sales $sales,
        private CategoryDiscounts $discounts,
        private DiscountCoverage $discountCoverage,
        private Campaigns $campaigns,
        private CeilingCheck $ceiling,
        private Words $words,
    ) {}

    public function preview(StoreDto $store, Campaign $campaign, ?string $after, ?string $itself): CampaignPreviewDto
    {
        $storeId = $store->storeId();
        $whole = $this->coverage->products([$campaign])[$campaign->runId] ?? [];
        // A product reached only through sizes picked by name shows those sizes alone.
        $picked = $campaign->targetIds(CampaignTargetKind::Size);
        $sizesOf = [];

        foreach ($whole === [] ? [] : $this->catalog->variantIdsOfProducts(array_map('strval', array_keys($whole)), true) as $productId => $sizes) {
            $sizesOf[(string) $productId] = $sizes;
        }

        foreach ($picked === [] ? [] : $this->catalog->variants($picked) as $variant) {
            if (! isset($whole[$variant->productId])) {
                $sizesOf[$variant->productId][] = $variant->id;
            }
        }

        $named = [];

        foreach (array_chunk(array_map('strval', array_keys($sizesOf)), 1000) as $chunk) {
            $named += $this->catalog->products($chunk);
        }

        $all = array_merge(...array_values($sizesOf));
        $facts = $retail = [];

        foreach (array_chunk($all, self::CHUNK) as $chunk) {
            $facts += $this->coverage->facts($chunk);
            $retail += $this->retailPrices->forVariants($storeId, $chunk);
        }

        $ceiling = $this->ceiling->ceiling($storeId) * 100;
        $judged = [];
        $reached = $skipped = 0;
        $deepest = null;
        $pricedSizes = [];

        foreach ($sizesOf as $productId => $sizes) {
            foreach ($sizes as $variantId) {
                $judged[$variantId] = $this->judge($campaign, $variantId, $facts[$variantId] ?? null, $retail[$variantId] ?? null, $ceiling);

                if ($judged[$variantId]['skipped'] === null) {
                    $reached++;
                    $deepest = max($deepest ?? 0, $judged[$variantId]['depth'] ?? 0);
                    $pricedSizes[$variantId] = (string) $productId;
                } else {
                    $skipped++;
                }
            }
        }

        $english = $this->words->locale() === 'en';
        $ordered = array_values(array_filter(array_map('strval', array_keys($sizesOf)), static fn (string $id): bool => isset($named[$id])));
        usort($ordered, static fn (string $a, string $b): int => [mb_strtolower(DiscountRows::name($named[$a], $english)), $a] <=> [mb_strtolower(DiscountRows::name($named[$b], $english)), $b]);
        $start = $after === null ? 0 : ((int) array_search($after, $ordered, true) + (in_array($after, $ordered, true) ? 1 : 0));
        $page = array_slice($ordered, $start, self::PER_PAGE);
        $pageSizes = array_merge(...array_map(static fn (string $id): array => $sizesOf[$id], $page));
        $variants = $pageSizes === [] ? [] : $this->catalog->variants($pageSizes);

        return new CampaignPreviewDto(
            array_map(static fn (string $productId): CampaignPreviewProductDto => self::product($named[$productId], array_map(
                static fn (string $variantId): CampaignPreviewSizeDto => new CampaignPreviewSizeDto(
                    $variantId,
                    $variants[$variantId]->code ?? '',
                    $retail[$variantId] ?? null,
                    $judged[$variantId]['price'],
                    $judged[$variantId]['depth'],
                    $judged[$variantId]['skipped'],
                ),
                $sizesOf[$productId],
            )), $page),
            $start + self::PER_PAGE < count($ordered) && $page !== [] ? $page[count($page) - 1] : null,
            count($ordered),
            $reached,
            $skipped,
            $deepest,
            $this->otherOffers($store, $campaign, $pricedSizes, $itself),
        );
    }

    /**
     * @param  array{productId: string, brandId: string, categoryIds: list<string>, archived: bool}|null  $facts
     * @return array{price: ?Money, depth: ?int, skipped: ?string}
     */
    private function judge(Campaign $campaign, string $variantId, ?array $facts, ?Money $retail, int $ceiling): array
    {
        if ($facts === null || $facts['archived']) {
            return ['price' => null, 'depth' => null, 'skipped' => 'archived'];
        }

        if ($retail === null) {
            return ['price' => null, 'depth' => null, 'skipped' => 'no_price'];
        }

        $priced = CampaignCoverage::priceOn($campaign, $variantId, $facts, $retail);

        if ($priced === null) {
            return ['price' => null, 'depth' => null, 'skipped' => 'zero_or_below'];
        }

        $skipped = match (true) {
            $priced['price']->compareTo($retail) >= 0 => 'not_below_retail',
            // Refused on saving in a store not wired; skipped by the run in a wired one (§9.6 #4-5).
            $priced['depth'] > $ceiling => 'past_ceiling',
            default => null,
        };

        return ['price' => $priced['price'], 'depth' => $priced['depth'], 'skipped' => $skipped];
    }

    /**
     * The sales on its sizes, the category discounts on its products and the other runs reaching its sizes,
     * whose dates meet its own.
     *
     * @param  array<string, string>  $sizes  size id => its product, those it prices
     * @return list<CampaignOfferDto>
     */
    private function otherOffers(StoreDto $store, Campaign $campaign, array $sizes, ?string $itself): array
    {
        if ($sizes === []) {
            return [];
        }

        $storeId = $store->storeId();
        $now = CarbonImmutable::now()->toDateTimeImmutable();
        $ids = array_map('strval', array_keys($sizes));
        $found = [];

        foreach (array_chunk($ids, 1000) as $chunk) {
            foreach ($this->sales->notEndedOn($storeId, $chunk, $now) as $sale) {
                if ($sale->overlaps($campaign->startsAt, $campaign->endsAt)) {
                    $shared = array_values(array_intersect($sale->variantIds, $ids));
                    $found['SALE|'.$sale->id] = new CampaignOfferDto(PriceKind::Sale, $sale->id, $sale->startsAt, $sale->endsAt, $sale->alwaysWins, $shared);
                }
            }
        }

        $discounts = array_values(array_filter(
            $this->discounts->notEnded($storeId, $now),
            static fn (CategoryDiscount $discount): bool => $discount->overlaps($campaign->startsAt, $campaign->endsAt) && $discount->lines->meets($campaign->lines),
        ));
        $reach = $this->discountCoverage->productsOfEach($discounts);

        foreach ($discounts as $discount) {
            $shared = array_map('strval', array_keys(array_filter($sizes, static fn (string $productId): bool => isset($reach[$discount->id][$productId]))));

            if ($shared !== []) {
                $found['CATEGORY|'.$discount->id] = new CampaignOfferDto(PriceKind::Category, $discount->id, $discount->startsAt, $discount->endsAt, $discount->alwaysWins, $shared);
            }
        }

        $others = array_values(array_filter(
            $this->campaigns->notEnded($storeId, $now),
            static fn (Campaign $other): bool => $other->runId !== $campaign->runId && $other->runId !== $itself && $other->overlaps($campaign->startsAt, $campaign->endsAt) && $other->lines->meets($campaign->lines),
        ));
        $products = $this->coverage->products($others);

        foreach ($others as $other) {
            $picked = array_fill_keys($other->targetIds(CampaignTargetKind::Size), true);
            $shared = array_map('strval', array_keys(array_filter($sizes, static fn (string $productId, string $variantId): bool => isset($picked[$variantId]) || isset($products[$other->runId][$productId]), ARRAY_FILTER_USE_BOTH)));

            if ($shared !== []) {
                $found['CAMPAIGN|'.$other->runId] = new CampaignOfferDto(PriceKind::Campaign, $other->runId, $other->startsAt, $other->endsAt, false, $shared);
            }
        }

        return array_values($found);
    }

    /**
     * @param  list<CampaignPreviewSizeDto>  $sizes
     */
    private static function product(ProductDto $product, array $sizes): CampaignPreviewProductDto
    {
        return new CampaignPreviewProductDto($product->id, $product->nameAr, $product->nameEn, $sizes);
    }
}
