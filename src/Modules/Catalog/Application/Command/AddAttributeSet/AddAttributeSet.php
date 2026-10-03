<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\AddAttributeSet;

/**
 * A new attribute set — the design's Variations (catalog.md §1.7): its name and its variant-making
 * attributes, in order.
 */
final readonly class AddAttributeSet
{
    /**
     * @param  list<string>  $attributeIds
     */
    public function __construct(
        public string $nameAr,
        public string $nameEn,
        public array $attributeIds,
    ) {}
}
