<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\Products;

/**
 * An attribute with its values, as a product's forms offer it (catalog.md §4.4 S9): the variant-making
 * attributes to choose a variant's values, "details only" ones for its details, filter ones for the
 * product's filters. Inactive values are offered only where a product already holds them.
 */
final readonly class AttributeChoice
{
    /**
     * @param  list<array{id: string, nameAr: string, nameEn: string, swatch: string|null, active: bool}>  $values  in order
     */
    public function __construct(
        public string $id,
        public string $nameAr,
        public string $nameEn,
        public string $kind,
        public ?string $unitAr,
        public ?string $unitEn,
        public bool $isColour,
        public bool $active,
        public array $values,
    ) {}
}
