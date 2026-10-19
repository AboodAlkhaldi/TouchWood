<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Prices;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Dto\ProductDto;
use Modules\Catalog\Public\Enums\ProductStage;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Pricing\Application\Query\OverlapsFor\OverlappingDiscount;
use Modules\Pricing\Application\Query\OverlapsFor\OverlappingSale;
use Modules\Pricing\Application\Query\PreviewCategoryDiscount\DiscountPreview;
use Modules\Pricing\Application\Query\PreviewCategoryDiscount\PreviewProduct;
use Modules\Pricing\Application\Query\PreviewCategoryDiscount\PreviewSize;
use Modules\Pricing\Domain\Repository\CategoryDiscounts;
use Modules\Pricing\Domain\Repository\RetailPrices;
use Modules\Pricing\Domain\Repository\Sales;
use Modules\Pricing\Domain\ValueObject\CategoryDiscount;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;

/**
 * **A category discount's preview** (pricing.md §1.5, §4.3.3 P3; owner, 2026-10-07): before it is saved
 * and on its page after - totals over every product it reaches in the store, then the products 20 at a
 * time by name, each priced size's retail price, its new price and the difference, or why it is skipped
 * (Q9); the products it leaves out; the sizes of the category with no price; and what it overlaps in its
 * dates - the sales on those sizes and the other discounts on the same products (§1.3's note).
 *
 * Its reads follow the category, not the store: the category's products (Catalog), their sizes in one
 * read, their prices a read a thousand sizes - never the store's whole price list - plus the overlap note
 * (about four reads of Catalog for each other discount not ended: README, "Known costs").
 */
final readonly class DiscountPreviewer
{
    /** Products a page of the preview (§4.3.3 P3). */
    public const int PER_PAGE = 20;

    /** Sizes a read. */
    private const int CHUNK = 1000;

    public function __construct(
        private DiscountCoverage $coverage,
        private RetailPrices $retailPrices,
        private CatalogApi $catalog,
        private Sales $sales,
        private CategoryDiscounts $discounts,
        private CeilingCheck $ceiling,
        private PriceViews $views,
        private Words $words,
    ) {}

    public function preview(StoreDto $store, CategoryDiscount $discount, ?string $after = null): DiscountPreview
    {
        $storeId = $store->storeId();
        $products = $this->coverage->products($discount);
        $named = [];

        // Named, and an archived product's sizes left out: none of them is on sale (Catalog). A read a
        // thousand products, under PostgreSQL's bind-parameter limit.
        foreach (array_chunk(array_values(array_unique([...array_map('strval', array_keys($products)), ...$discount->excludedProductIds])), self::CHUNK) as $chunk) {
            $named += $this->catalog->products($chunk);
        }

        $live = array_values(array_filter(array_map('strval', array_keys($products)), static fn (string $id): bool => isset($named[$id]) && $named[$id]->stage !== ProductStage::Archived));
        $sizesOf = $this->catalog->variantIdsOfProducts($live);
        $productOf = [];

        foreach ($sizesOf as $productId => $sizes) {
            foreach ($sizes as $variantId) {
                $productOf[$variantId] = (string) $productId;
            }
        }

        $retail = [];

        foreach (array_chunk(array_keys($productOf), self::CHUNK) as $chunk) {
            $retail += $this->retailPrices->forVariants($storeId, $chunk);
        }

        // In a wired store a fixed amount skips a size it would take past the ceiling (sync.md §9.3 #17).
        $ceiling = $discount->amount !== null && $this->ceiling->skipsPastCeiling($storeId) ? $this->ceiling->ceiling($storeId) * 100 : PHP_INT_MAX;
        $switchedOn = array_fill_keys($this->catalog->switchedOnVariantIds($storeId), true);
        $byProduct = [];
        $reachedSizes = $skippedSizes = $unpriced = 0;
        $deepest = null;

        foreach ($productOf as $variantId => $productId) {
            $price = $retail[$variantId] ?? null;

            if ($price === null) {
                $unpriced += isset($switchedOn[$variantId]) ? 1 : 0;

                continue;
            }

            $byProduct[$productId][] = $variantId;
            $new = $discount->priceOn($price);

            if ($new === null || $discount->depthOn($price) > $ceiling) {
                $skippedSizes++;
            } else {
                $reachedSizes++;
                $deepest = max($deepest ?? 0, $discount->depthOn($price));
            }
        }

        $english = $this->words->locale() === 'en';
        $ordered = array_values(array_filter(array_keys($byProduct), static fn (string $id): bool => isset($named[$id])));
        usort($ordered, static fn (string $a, string $b): int => [mb_strtolower(DiscountRows::name($named[$a], $english)), $a] <=> [mb_strtolower(DiscountRows::name($named[$b], $english)), $b]);
        $start = $after === null ? 0 : ((int) array_search($after, $ordered, true) + (in_array($after, $ordered, true) ? 1 : 0));
        $page = array_slice($ordered, $start, self::PER_PAGE);
        $pageSizes = array_merge(...array_map(static fn (string $id): array => $byProduct[$id], $page));
        $variants = $pageSizes === [] ? [] : $this->catalog->variants($pageSizes);

        $reached = array_map(fn (string $productId): PreviewProduct => self::product($named[$productId], array_values(array_filter(array_map(
            fn (string $variantId): ?PreviewSize => $this->size($variantId, $retail[$variantId], $discount, $ceiling, $variants[$variantId]->code ?? '', isset($variants[$variantId]) ? $this->views->sizeName($variants[$variantId]->values) : '', $store),
            $byProduct[$productId],
        )))), $page);

        return new DiscountPreview(
            $reached,
            $start + self::PER_PAGE < count($ordered) && $page !== [] ? $page[count($page) - 1] : null,
            count($ordered),
            $reachedSizes,
            $skippedSizes,
            $unpriced,
            $deepest,
            array_values(array_filter(array_map(
                static fn (string $productId): ?PreviewProduct => isset($named[$productId]) ? self::product($named[$productId], []) : null,
                $discount->excludedProductIds,
            ))),
            $this->overlappingSales($storeId, $discount, array_merge(...array_values($byProduct))),
            $this->overlappingDiscounts($storeId, $discount, $products),
        );
    }

    private function size(string $variantId, Money $retail, CategoryDiscount $discount, int $ceiling, string $code, string $name, StoreDto $store): ?PreviewSize
    {
        $price = $this->views->money($retail, $store);

        if ($price === null) {
            return null;
        }

        $new = $discount->priceOn($retail);

        // Skipped, and why (Q9): a fixed amount at or above the price; in a wired store, past the ceiling.
        if ($new === null || $discount->depthOn($retail) > $ceiling) {
            return new PreviewSize($variantId, $code, $name, $price, null, null, $new === null ? 'zero_or_below' : 'past_ceiling');
        }

        return new PreviewSize(
            $variantId,
            $code,
            $name,
            $price,
            $this->views->money($new, $store),
            $this->views->money(Money::of($retail->minorUnits - $new->minorUnits, $retail->currencyCode), $store),
            null,
        );
    }

    /**
     * @param  list<string>  $sizes
     * @return list<OverlappingSale>
     */
    private function overlappingSales(StoreId $store, CategoryDiscount $discount, array $sizes): array
    {
        $now = CarbonImmutable::now()->toDateTimeImmutable();
        $found = [];

        foreach (array_chunk($sizes, self::CHUNK) as $chunk) {
            foreach ($this->sales->notEndedOn($store, $chunk, $now) as $sale) {
                if ($sale->overlaps($discount->startsAt, $discount->endsAt)) {
                    $found[$sale->id] = new OverlappingSale(
                        $sale->id,
                        $sale->percentBasisPoints,
                        $sale->price?->minorUnits,
                        $sale->price?->currencyCode,
                        $sale->startsAt->format(DateTimeInterface::ATOM),
                        $sale->endsAt?->format(DateTimeInterface::ATOM),
                        $sale->alwaysWins,
                        array_values(array_intersect($sale->variantIds, $sizes)),
                    );
                }
            }
        }

        return array_values($found);
    }

    /**
     * @param  array<string, true>  $mine  the products it reaches
     * @return list<OverlappingDiscount>
     */
    private function overlappingDiscounts(StoreId $store, CategoryDiscount $discount, array $mine): array
    {
        $found = [];
        $others = array_values(array_filter(
            $this->discounts->notEnded($store, CarbonImmutable::now()->toDateTimeImmutable()),
            static fn (CategoryDiscount $other): bool => $other->id !== $discount->id && $other->overlaps($discount->startsAt, $discount->endsAt),
        ));
        // Every other discount's products in one read, however many there are (the preview within 15).
        $reach = $this->coverage->productsOfEach($others);

        foreach ($others as $other) {
            if (array_intersect_key($mine, $reach[$other->id] ?? []) !== []) {
                $found[] = new OverlappingDiscount(
                    $other->id,
                    $other->categoryId,
                    $other->percentBasisPoints,
                    $other->amount?->minorUnits,
                    $other->amount?->currencyCode,
                    $other->lines->value,
                    $other->startsAt->format(DateTimeInterface::ATOM),
                    $other->endsAt?->format(DateTimeInterface::ATOM),
                    $other->alwaysWins,
                );
            }
        }

        return $found;
    }

    /**
     * @param  list<PreviewSize>  $sizes
     */
    private static function product(ProductDto $product, array $sizes): PreviewProduct
    {
        return new PreviewProduct($product->id, $product->nameAr, $product->nameEn, $sizes);
    }
}
