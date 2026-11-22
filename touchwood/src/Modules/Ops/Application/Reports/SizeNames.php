<?php

declare(strict_types=1);

namespace Modules\Ops\Application\Reports;

use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Dto\ProductDto;
use Modules\Catalog\Public\Dto\VariantValueDto;
use Modules\Catalog\Public\Enums\ProductStage;

/**
 * Names a page of sizes or products through Catalog (ops.md §2.3: "Catalog for names") - two reads a page
 * however long, never one a row. A size or product Catalog no longer knows is named by nothing.
 */
final readonly class SizeNames
{
    public function __construct(
        private CatalogApi $catalog,
    ) {}

    /**
     * Each size's product, values ("Colour: Oak · Width: 80 cm") and code, keyed by size id.
     *
     * @param  list<string>  $variantIds
     * @return array<string, array{product: string, size: string, code: string}>
     */
    public function sizes(array $variantIds, string $locale): array
    {
        $variants = $this->catalog->variants($variantIds);
        $products = $this->catalog->products(array_values(array_unique(array_map(static fn ($variant): string => $variant->productId, array_values($variants)))));

        $names = [];
        foreach ($variants as $id => $variant) {
            $names[$id] = [
                'product' => self::product($products[$variant->productId] ?? null, $locale),
                'size' => implode(' · ', array_map(static fn (VariantValueDto $value): string => $value->attribute->in($locale).': '.$value->value->in($locale), $variant->values)),
                'code' => $variant->code,
            ];
        }

        return $names;
    }

    /**
     * Products by id.
     *
     * @param  list<string>  $productIds
     * @return array<string, ProductDto>
     */
    public function products(array $productIds): array
    {
        return $this->catalog->products(array_values(array_unique($productIds)));
    }

    /** A product's name in the reader's language: English falls back to Arabic, which every product has. */
    public static function product(?ProductDto $product, string $locale): string
    {
        return $product === null ? '' : ($locale === 'en' ? ($product->nameEn ?? $product->nameAr) : $product->nameAr);
    }

    public static function archived(?ProductDto $product): bool
    {
        return $product?->stage === ProductStage::Archived;
    }
}
