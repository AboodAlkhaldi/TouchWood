<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\Products;

/**
 * A product as the products list shows it (catalog.md §4.4 S8). With a store chosen, its lowest price and
 * how many of its sizes can't be ordered there, from Catalog's own facts (`store_variant_facts`, pushed
 * by Pricing and Inventory; pricing.md §9.4 Q5): never read from either module.
 */
final readonly class ProductRow
{
    /**
     * @param  list<string>  $codes  every code its variants carry, in order
     * @param  list<string>  $onIn  the stores where any of its variants is switched on, as ids
     * @param  string|null  $storeState  with a store chosen: ProductFilter's ON, OFF, NOT_CHOSEN or NOT_AVAILABLE
     * @param  array{minor: int, currency: string}|null  $lowestPrice  with a store chosen: the lowest one-piece price now of its sizes switched on there
     * @param  int  $cantOrder  with a store chosen: how many of its sizes switched on there can't be ordered now
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
        public ?array $lowestPrice = null,
        public int $cantOrder = 0,
    ) {}
}
