<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\AddValueFromProduct;

/**
 * A new value of a variant-making attribute, made from a product's Variants tab — "New value…" in
 * Add Variant, Edit and Add Attribute (catalog.md §1.7, P28, amendment 16(c)).
 */
final readonly class AddValueFromProduct
{
    public function __construct(
        public string $productId,
        public string $attributeId,
        public string $nameAr,
        public string $nameEn,
        public ?string $swatch = null,
    ) {}
}
