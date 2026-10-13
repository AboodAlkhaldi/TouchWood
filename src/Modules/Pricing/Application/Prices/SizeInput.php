<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Prices;

use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Dto\VariantDto;
use Modules\Catalog\Public\Enums\ProductStage;
use Modules\Pricing\Domain\Exception\InvalidPricingInput;

/**
 * The sizes a change names, as callers write them, checked in two reads however many (catalog.md
 * amendment 16(i)): each must exist and be neither archived nor of an archived product - an archived
 * size is read-only in Pricing until it is restored, its price kept as it is (owner, 2026-10-11).
 */
final readonly class SizeInput
{
    public function __construct(
        private CatalogApi $catalog,
    ) {}

    /**
     * @param  list<string>  $given
     * @return array<string, string> each id as given => the size's own id
     *
     * @throws InvalidPricingInput
     */
    public function check(array $given): array
    {
        $variants = $this->catalog->variants($given);
        $products = $this->catalog->products(array_values(array_unique(array_map(
            static fn (VariantDto $variant): string => $variant->productId,
            array_values($variants),
        ))));
        $own = [];

        foreach ($given as $id) {
            // Keyed by the size's own id, as Catalog writes it: lower case, no spaces around.
            $variant = $variants[strtolower(trim($id))] ?? null;
            $product = $variant === null ? null : ($products[$variant->productId] ?? null);

            if ($variant === null || $variant->isArchived || $product === null || $product->stage === ProductStage::Archived) {
                throw new InvalidPricingInput('variant');
            }

            $own[$id] = $variant->id;
        }

        return $own;
    }
}
