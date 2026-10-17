<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Prices;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Dto\ProductDto;
use Modules\Pricing\Application\Query\OverlapsFor\OverlappingDiscount;
use Modules\Pricing\Application\Query\OverlapsFor\OverlappingSale;
use Modules\Pricing\Application\Query\PreviewCategoryDiscount\DiscountPreview;
use Modules\Pricing\Application\Query\PreviewCategoryDiscount\PreviewProduct;
use Modules\Pricing\Application\Query\PreviewCategoryDiscount\PreviewSize;
use Modules\Pricing\Domain\Repository\CategoryDiscounts;
use Modules\Pricing\Domain\Repository\RetailPrices;
use Modules\Pricing\Domain\Repository\Sales;
use Modules\Pricing\Domain\ValueObject\CategoryDiscount;
use Shared\Domain\ValueObject\StoreId;

/**
 * **A category discount's preview** (pricing.md §1.5; owner, 2026-10-07): before it is saved and on its
 * page after - every product it reaches in the store, each priced size's retail price, its new price and
 * the difference, a size a fixed amount skips marked so; the products it leaves out; and what it
 * overlaps in its dates - the sales on those sizes and the other discounts on the same products (§1.3's
 * note). Its reads grow with the store's priced sizes (README, "Known costs").
 */
final readonly class DiscountPreviewer
{
    /** Sizes a read. */
    private const int CHUNK = 1000;

    public function __construct(
        private DiscountCoverage $coverage,
        private RetailPrices $retailPrices,
        private CatalogApi $catalog,
        private Sales $sales,
        private CategoryDiscounts $discounts,
        private CeilingCheck $ceiling,
    ) {}

    public function preview(StoreId $store, CategoryDiscount $discount): DiscountPreview
    {
        $sizes = $this->coverage->pricedSizes($store, [$discount]);
        // In a wired store a fixed amount skips a size it would take past the ceiling (sync.md §9.3 #17).
        $ceiling = $discount->amount !== null && $this->ceiling->skipsPastCeiling($store) ? $this->ceiling->ceiling($store) * 100 : PHP_INT_MAX;
        $retail = [];

        foreach (array_chunk(array_keys($sizes), self::CHUNK) as $chunk) {
            $retail += $this->retailPrices->forVariants($store, $chunk);
        }

        $variants = [];

        foreach (array_chunk(array_keys($sizes), self::CHUNK) as $chunk) {
            $variants += $this->catalog->variants($chunk);
        }

        $products = $this->catalog->products(array_values(array_unique([...array_values($sizes), ...$discount->excludedProductIds])));
        $reached = [];

        foreach ($sizes as $variantId => $productId) {
            $price = $retail[$variantId] ?? null;

            if ($price === null) {
                continue;
            }

            $new = $discount->depthOn($price) > $ceiling ? null : $discount->priceOn($price);
            $reached[$productId][] = new PreviewSize(
                $variantId,
                $variants[$variantId]->code ?? '',
                $price->minorUnits,
                $new?->minorUnits,
                $new === null ? null : $price->minorUnits - $new->minorUnits,
            );
        }

        return new DiscountPreview(
            array_values(array_filter(array_map(
                static fn (string $productId): ?PreviewProduct => isset($products[$productId]) ? self::product($products[$productId], $reached[$productId]) : null,
                array_keys($reached),
            ))),
            array_values(array_filter(array_map(
                static fn (string $productId): ?PreviewProduct => isset($products[$productId]) ? self::product($products[$productId], []) : null,
                $discount->excludedProductIds,
            ))),
            $this->overlappingSales($store, $discount, array_keys($sizes)),
            $this->overlappingDiscounts($store, $discount),
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
     * @return list<OverlappingDiscount>
     */
    private function overlappingDiscounts(StoreId $store, CategoryDiscount $discount): array
    {
        $mine = $this->coverage->products($discount);
        $found = [];

        foreach ($this->discounts->notEnded($store, CarbonImmutable::now()->toDateTimeImmutable()) as $other) {
            if ($other->id !== $discount->id && $other->overlaps($discount->startsAt, $discount->endsAt)
                && array_intersect_key($mine, $this->coverage->products($other)) !== []) {
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
