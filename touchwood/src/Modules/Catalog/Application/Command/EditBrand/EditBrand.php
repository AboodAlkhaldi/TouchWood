<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\EditBrand;

/**
 * Everything staff edit in a brand's form (catalog.md §1.6), sent whole. A slug left empty is made
 * again from its name; the one it replaces stays held, redirecting (§1.1). Its place in the brands'
 * order is not in the form: the list is ordered by dragging (amendment 16(d), `OrderBrands`).
 */
final readonly class EditBrand
{
    /**
     * @param  array<array-key, mixed>|null  $descriptionAr
     * @param  array<array-key, mixed>|null  $descriptionEn
     */
    public function __construct(
        public string $brandId,
        public string $nameAr,
        public string $nameEn,
        public string $agencyType,
        public bool $showInDefaultListings,
        public ?string $slugAr = null,
        public ?string $slugEn = null,
        public ?array $descriptionAr = null,
        public ?array $descriptionEn = null,
        public ?string $logoMediaId = null,
        public ?string $originCountry = null,
    ) {}
}
