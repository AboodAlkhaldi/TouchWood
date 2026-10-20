<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Order;

use Modules\Catalog\Public\Contracts\CatalogApi;

/**
 * What an order line keeps of a size (sales.md §1.3 step 7): its product, the product's names in both
 * languages — the English falling back to the Arabic (answer 44) — the size's values and its code, in two
 * reads however many sizes. A size Catalog no longer has is left out.
 */
final readonly class SizeRecords
{
    public function __construct(
        private CatalogApi $catalog,
    ) {}

    /**
     * @param  list<string>  $variantIds
     * @return array<string, array{productId: string, nameAr: string, nameEn: string, values: list<array{attributeAr: string, attributeEn: string, valueAr: string, valueEn: string}>, code: string}>
     */
    public function of(array $variantIds): array
    {
        if ($variantIds === []) {
            return [];
        }

        $variants = $this->catalog->variants(array_values(array_unique($variantIds)));
        $products = $this->catalog->products(array_values(array_unique(array_map(static fn ($variant): string => $variant->productId, $variants))));
        $records = [];

        foreach ($variants as $id => $variant) {
            $product = $products[$variant->productId] ?? null;

            if ($product !== null) {
                $records[$id] = [
                    'productId' => $variant->productId,
                    'nameAr' => $product->nameAr,
                    'nameEn' => $product->nameEn ?? $product->nameAr,
                    'values' => array_map(static fn ($value): array => [
                        'attributeAr' => $value->attribute->ar, 'attributeEn' => $value->attribute->en, 'valueAr' => $value->value->ar, 'valueEn' => $value->value->en,
                    ], $variant->values),
                    'code' => $variant->code,
                ];
            }
        }

        return $records;
    }
}
