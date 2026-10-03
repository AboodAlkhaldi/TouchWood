<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\EditAttributeSet;

/**
 * An attribute set's form, sent whole (catalog.md §1.7).
 */
final readonly class EditAttributeSet
{
    /**
     * @param  list<string>  $attributeIds
     */
    public function __construct(
        public string $setId,
        public string $nameAr,
        public string $nameEn,
        public array $attributeIds,
    ) {}
}
