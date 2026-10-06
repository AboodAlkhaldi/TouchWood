<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\AddAttributeValue;

/**
 * A new value of a filter or a variant-making attribute (catalog.md §1.7); a colour attribute's value
 * with its swatch, `#rrggbb`.
 */
final readonly class AddAttributeValue
{
    public function __construct(
        public string $attributeId,
        public string $nameAr,
        public string $nameEn,
        public ?string $swatch = null,
        public int $position = 0,
    ) {}
}
