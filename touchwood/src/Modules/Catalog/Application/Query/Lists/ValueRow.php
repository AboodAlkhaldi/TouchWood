<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\Lists;

/**
 * One value of an attribute (catalog.md §1.7): its swatch on a colour attribute, and whether a variant
 * or a product's filters carry it, for Delete.
 */
final readonly class ValueRow
{
    public function __construct(
        public string $id,
        public string $nameAr,
        public string $nameEn,
        public ?string $swatch,
        public bool $active,
        public int $position,
        public bool $inUse,
    ) {}
}
