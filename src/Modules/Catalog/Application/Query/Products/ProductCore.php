<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\Products;

/**
 * One product as every tab of its page shows it above the tabs (catalog.md §4.4 S9), and what its
 * readiness and its handlers' checks need: where it is on, whether it has a variant, whether its
 * category may hold it.
 */
final readonly class ProductCore
{
    /**
     * @param  array<string, mixed>|null  $descriptionAr  the structured text (§1.1), or none
     * @param  array<string, mixed>|null  $descriptionEn
     * @param  list<array{ar: string, en: string}>  $categoryPath  from the top down to its category
     * @param  list<string>  $variantAttributeIds  its variants' attributes, in its order (amendment 16(b))
     * @param  list<string>  $codes
     * @param  list<string>  $gallery  media ids, the card's photo first
     * @param  list<string>  $onIn  the stores where any of its variants is switched on, as ids
     * @param  array{variants: int, photos: int, searchWords: int, filterValues: int, related: int, goesWith: int}  $counts
     */
    public function __construct(
        public string $id,
        public string $nameAr,
        public ?string $nameEn,
        public ?string $slugAr,
        public ?string $slugEn,
        public string $stage,
        public ?string $archivedFrom,
        public string $brandId,
        public string $brandNameAr,
        public string $brandNameEn,
        public ?string $categoryId,
        public array $categoryPath,
        public bool $categoryShowable,
        public ?string $warrantyId,
        public array $variantAttributeIds,
        public ?array $descriptionAr,
        public ?array $descriptionEn,
        public bool $hiddenByCategory,
        public bool $hiddenByBrand,
        public array $codes,
        public array $gallery,
        public array $onIn,
        public bool $hasVariant,
        public array $counts,
    ) {}
}
