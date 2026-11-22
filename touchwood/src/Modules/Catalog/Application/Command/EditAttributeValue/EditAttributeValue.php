<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\EditAttributeValue;

/**
 * A value's form, sent whole (catalog.md §1.7). It stays in its attribute, in its place there: the
 * values are ordered by dragging (amendment 16(d), `OrderAttributeValues`).
 */
final readonly class EditAttributeValue
{
    public function __construct(
        public string $valueId,
        public string $nameAr,
        public string $nameEn,
        public ?string $swatch = null,
    ) {}
}
