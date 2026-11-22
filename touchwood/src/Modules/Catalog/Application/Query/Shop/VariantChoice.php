<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\Shop;

/**
 * One value a variant is made of, as the product page offers it to pick (catalog.md §1.7).
 */
final readonly class VariantChoice
{
    public function __construct(
        public string $attributeId,
        public string $attribute,
        public string $valueId,
        public string $value,
        public ?string $swatch,
    ) {}
}
