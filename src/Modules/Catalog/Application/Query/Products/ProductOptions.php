<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\Products;

/**
 * What a product's Details can point at (catalog.md §4.4 S9): the active brands, the categories that
 * may hold products - active, with no sub-category - each with its path, the active warranties and
 * the active variations with their attributes in order.
 */
final readonly class ProductOptions
{
    /**
     * @param  list<array{id: string, nameAr: string, nameEn: string, isDefault: bool}>  $brands
     * @param  list<array{id: string, path: list<array{ar: string, en: string}>}>  $categories
     * @param  list<array{id: string, nameAr: string, nameEn: string, periodMonths: int|null}>  $warranties
     * @param  list<array{id: string, nameAr: string, nameEn: string, attributeIds: list<string>}>  $variations
     */
    public function __construct(
        public array $brands,
        public array $categories,
        public array $warranties,
        public array $variations,
    ) {}
}
