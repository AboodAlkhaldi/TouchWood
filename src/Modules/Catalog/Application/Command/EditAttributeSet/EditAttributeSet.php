<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\EditAttributeSet;

/**
 * An attribute set's form, sent whole (catalog.md §1.7).
 */
final readonly class EditAttributeSet
{
    /**
     * @param  array<array-key, mixed>  $attributeIds  in order, as the request sent them
     */
    public function __construct(
        public string $setId,
        public string $nameAr,
        public string $nameEn,
        public array $attributeIds,
    ) {}
}
