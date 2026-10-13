<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Prices;

use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Dto\ProductDto;
use Modules\Pricing\Application\Query\PreviewCategoryDiscount\DiscountPreview;
use Modules\Pricing\Application\Query\PreviewCategoryDiscount\PreviewProduct;
use Modules\Pricing\Application\Query\PreviewCategoryDiscount\PreviewSize;
use Modules\Pricing\Domain\Repository\RetailPrices;
use Modules\Pricing\Domain\ValueObject\CategoryDiscount;
use Shared\Domain\ValueObject\StoreId;

/**
 * **A category discount's preview** (pricing.md §1.5; owner, 2026-10-07): before it is saved and on its
 * page after - every product it reaches in the store, each priced size's retail price, its new price and
 * the difference, a size a fixed amount skips marked so; and the products it leaves out. Names in two
 * reads of Catalog however many (§2.4).
 */
final readonly class DiscountPreviewer
{
    /** Sizes a read. */
    private const int CHUNK = 1000;

    public function __construct(
        private DiscountCoverage $coverage,
        private RetailPrices $retailPrices,
        private CatalogApi $catalog,
    ) {}

    public function preview(StoreId $store, CategoryDiscount $discount): DiscountPreview
    {
        $sizes = $this->coverage->pricedSizes($store, [$discount]);
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

            $new = $discount->priceOn($price);
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
        );
    }

    /**
     * @param  list<PreviewSize>  $sizes
     */
    private static function product(ProductDto $product, array $sizes): PreviewProduct
    {
        return new PreviewProduct($product->id, $product->nameAr, $product->nameEn, $sizes);
    }
}
