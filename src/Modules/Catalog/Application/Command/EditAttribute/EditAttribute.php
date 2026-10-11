<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\EditAttribute;

/**
 * An attribute's form, sent whole (catalog.md §1.7). Its place among the attributes is not in the
 * form: the list is ordered by dragging (amendment 16(d), `OrderAttributes`).
 */
final readonly class EditAttribute
{
    public function __construct(
        public string $attributeId,
        public string $nameAr,
        public string $nameEn,
        public string $kind,
        public ?string $unitAr = null,
        public ?string $unitEn = null,
        public bool $isColour = false,
    ) {}
}
