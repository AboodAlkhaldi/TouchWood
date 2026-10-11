<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\Products;

/**
 * What the products screens can point at (catalog.md §4.4 S8, S9): every brand, every category that
 * holds products - with no sub-category - with its path, every warranty, each saying whether it is
 * active (a new choice takes an active one; the list's filters and a product's own keep the others).
 */
final readonly class ProductOptions
{
    /**
     * @param  list<array{id: string, nameAr: string, nameEn: string, isDefault: bool, active: bool}>  $brands
     * @param  list<array{id: string, path: list<array{ar: string, en: string}>, active: bool}>  $categories
     * @param  list<array{id: string, nameAr: string, nameEn: string, periodMonths: int|null, active: bool}>  $warranties
     */
    public function __construct(
        public array $brands,
        public array $categories,
        public array $warranties,
    ) {}
}
