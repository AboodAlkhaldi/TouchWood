<?php

declare(strict_types=1);

namespace Modules\Catalog\Public\Dto;

use Modules\Catalog\Public\Enums\ProductStage;

/**
 * A product as the modules above Catalog read it (catalog.md §2.1). A draft may have its Arabic name
 * only (amendment 3(g)); everything ever shown has both.
 */
final readonly class ProductDto
{
    public function __construct(
        public string $id,
        public string $nameAr,
        public ?string $nameEn,
        public ProductStage $stage,
        public string $brandId,
        public ?string $categoryId,
        public ?string $warrantyId,
    ) {}
}
