<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Command\EditProductDetails;

/**
 * A product's own form, sent whole (catalog.md §1.1): names, slugs, the two descriptions (the
 * structured text, decoded), its brand, category, warranty and attribute set.
 */
final readonly class EditProductDetails
{
    /**
     * @param  array<array-key, mixed>|null  $descriptionAr
     * @param  array<array-key, mixed>|null  $descriptionEn
     */
    public function __construct(
        public string $productId,
        public string $nameAr,
        public ?string $nameEn,
        public string $brandId,
        public ?string $slugAr = null,
        public ?string $slugEn = null,
        public ?array $descriptionAr = null,
        public ?array $descriptionEn = null,
        public ?string $categoryId = null,
        public ?string $warrantyId = null,
        public ?string $attributeSetId = null,
    ) {}
}
