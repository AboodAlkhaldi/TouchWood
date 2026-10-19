<?php

declare(strict_types=1);

namespace Modules\Catalog\Public\Dto;

/**
 * **A size as a store-wide list shows it** (catalog.md amendment 23(a)): its product's names, a photo -
 * its own first, else its product's -, its values and its code, and what a row marks on it: whether it
 * or its product is archived, and whether the store has it switched on (a size priced or stocked there
 * though switched off is named by `only`).
 */
final readonly class FoundVariantDto
{
    /**
     * @param  list<VariantValueDto>  $values  in the product's order of its variant attributes; none for a product with one size
     * @param  int  $position  the size's place among its product's sizes
     * @param  bool  $archived  the size, or its product, is archived
     */
    public function __construct(
        public string $variantId,
        public string $productId,
        public string $productNameAr,
        public ?string $productNameEn,
        public ?string $photoMediaId,
        public string $code,
        public array $values,
        public int $position,
        public bool $archived,
        public bool $switchedOn,
    ) {}
}
