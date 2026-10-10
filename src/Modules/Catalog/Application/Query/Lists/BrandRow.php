<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\Lists;

/**
 * A brand as the brands screen lists it (catalog.md §1.6, §4.4 S1).
 */
final readonly class BrandRow
{
    /**
     * @param  array<string, mixed>|null  $descriptionAr  the structured text (§1.1), or none
     * @param  array<string, mixed>|null  $descriptionEn
     */
    public function __construct(
        public string $id,
        public int $number,
        public string $nameAr,
        public string $nameEn,
        public ?string $slugAr,
        public ?string $slugEn,
        public string $agencyType,
        public bool $showInDefaultListings,
        public bool $isDefault,
        public bool $active,
        public int $position,
        public ?string $originCountry,
        public ?string $logoMediaId,
        public ?array $descriptionAr,
        public ?array $descriptionEn,
        public int $products,
    ) {}
}
