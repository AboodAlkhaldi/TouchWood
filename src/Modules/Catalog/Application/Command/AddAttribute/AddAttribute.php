<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\AddAttribute;

/**
 * A new attribute (catalog.md §1.7): its name, its job (`INFORMATIONAL`, `FILTERABLE`, `VARIANT`),
 * an optional unit in both languages, and whether it is a colour.
 */
final readonly class AddAttribute
{
    public function __construct(
        public string $nameAr,
        public string $nameEn,
        public string $kind,
        public ?string $unitAr = null,
        public ?string $unitEn = null,
        public bool $isColour = false,
        public int $position = 0,
    ) {}
}
