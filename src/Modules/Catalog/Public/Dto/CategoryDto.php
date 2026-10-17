<?php

declare(strict_types=1);

namespace Modules\Catalog\Public\Dto;

/**
 * A category as the modules above Catalog read it (catalog.md §2.1, amendment 17): for Pricing's
 * category discounts, whether it exists and is switched on.
 */
final readonly class CategoryDto
{
    public function __construct(
        public string $id,
        public ?string $parentId,
        public string $nameAr,
        public string $nameEn,
        public bool $isActive,
    ) {}
}
