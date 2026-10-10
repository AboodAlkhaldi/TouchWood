<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\Products;

/**
 * A product as the products list shows it (catalog.md §4.4 S8): never a price or stock (stage 5).
 */
final readonly class ProductRow
{
    /**
     * @param  list<string>  $codes  every code its variants carry, in order
     * @param  list<string>  $onIn  the stores where any of its variants is switched on, as ids
     * @param  string|null  $storeState  with a store chosen: ProductFilter's ON, OFF, NOT_CHOSEN or NOT_AVAILABLE
     */
    public function __construct(
        public string $id,
        public string $nameAr,
        public ?string $nameEn,
        public array $codes,
        public string $stage,
        public string $brandId,
        public string $brandNameAr,
        public string $brandNameEn,
        public ?string $categoryId,
        public ?string $categoryNameAr,
        public ?string $categoryNameEn,
        public ?string $photoMediaId,
        public int $variants,
        public array $onIn,
        public ?string $storeState,
        public int $storeVariantsOn,
    ) {}
}
