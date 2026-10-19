<?php

declare(strict_types=1);

namespace Modules\Sync\Application\Report;

use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Dto\VariantValueDto;

/**
 * The sizes a page names, with their product, code and values (in the reader's language, "60 cm") - two
 * Catalog reads however many (catalog.md amendment 16(i)). A size deleted since is left out: its line
 * keeps the code the provider sent.
 */
final readonly class SizeNames
{
    public function __construct(
        private CatalogApi $catalog,
        private SyncWords $words,
    ) {}

    /**
     * @param  list<string|null>  $variantIds
     * @return array<string, array{productId: string, code: string, nameAr: string, nameEn: string|null, values: list<string>}>
     */
    public function of(array $variantIds): array
    {
        if (array_filter($variantIds, static fn (?string $id): bool => $id !== null) === []) {
            return [];
        }

        $locale = $this->words->locale();
        $variants = $this->catalog->variants(array_values(array_unique(array_filter($variantIds, static fn (?string $id): bool => $id !== null))));
        $products = $this->catalog->products(array_values(array_unique(array_map(static fn ($variant): string => $variant->productId, array_values($variants)))));
        $named = [];

        foreach ($variants as $id => $variant) {
            $product = $products[$variant->productId] ?? null;

            if ($product !== null) {
                $named[strtolower((string) $id)] = [
                    'productId' => $variant->productId,
                    'code' => $variant->code,
                    'nameAr' => $product->nameAr,
                    'nameEn' => $product->nameEn,
                    'values' => array_map(static fn (VariantValueDto $value): string => $value->value->in($locale), $variant->values),
                ];
            }
        }

        return $named;
    }
}
