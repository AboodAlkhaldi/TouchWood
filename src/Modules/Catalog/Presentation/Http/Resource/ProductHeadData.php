<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One product above its tabs (catalog.md §4.4 S9): its names, addresses, stage, codes, what it
 * points at, and where it is on.
 */
#[TypeScript]
final class ProductHeadData extends Data
{
    /**
     * @param  string|null  $categoryPathAr  its category, by its path from the top
     * @param  list<string>  $setAttributeIds  its variation's attributes, in order
     * @param  string  $descriptionAr  as typed, with the products file's marks (P1)
     * @param  list<string>  $codes
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
        public ?string $categoryPathAr,
        public ?string $categoryPathEn,
        public ?string $warrantyId,
        public ?string $attributeSetId,
        public ?string $attributeSetNameAr,
        public ?string $attributeSetNameEn,
        public array $setAttributeIds,
        public string $descriptionAr,
        public string $descriptionEn,
        public bool $hiddenByCategory,
        public bool $hiddenByBrand,
        public array $codes,
        public ProductCountsData $counts,
    ) {}
}
