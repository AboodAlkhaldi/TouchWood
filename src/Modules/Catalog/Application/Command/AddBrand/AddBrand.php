<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\AddBrand;

/**
 * A new brand (catalog.md §1.6). Slugs left empty are made from the names (§9.3 #3); a description is
 * the structured text of §1.1, decoded, in both languages or in neither.
 */
final readonly class AddBrand
{
    /**
     * @param  array<array-key, mixed>|null  $descriptionAr
     * @param  array<array-key, mixed>|null  $descriptionEn
     */
    public function __construct(
        public string $nameAr,
        public string $nameEn,
        public string $agencyType,
        public bool $showInDefaultListings = true,
        public int $position = 0,
        public ?string $slugAr = null,
        public ?string $slugEn = null,
        public ?array $descriptionAr = null,
        public ?array $descriptionEn = null,
        public ?string $logoMediaId = null,
        public ?string $originCountry = null,
    ) {}
}
