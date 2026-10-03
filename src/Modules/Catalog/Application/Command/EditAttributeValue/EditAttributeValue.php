<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\EditAttributeValue;

/**
 * A value's form, sent whole (catalog.md §1.7). It stays in its attribute.
 */
final readonly class EditAttributeValue
{
    public function __construct(
        public string $valueId,
        public string $nameAr,
        public string $nameEn,
        public ?string $swatch = null,
        public int $position = 0,
    ) {}
}
