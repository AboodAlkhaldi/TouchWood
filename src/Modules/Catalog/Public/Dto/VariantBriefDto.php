<?php

declare(strict_types=1);

namespace Modules\Catalog\Public\Dto;

use Modules\Catalog\Public\Enums\ProductStage;

/**
 * **A size in brief** (catalog.md amendment 23(h); stage 5's screens step 4): its product, the product's
 * names and stage, the size's place among its product's sizes and whether the size itself is archived -
 * what Inventory's list needs to leave archived sizes off and order the rest by name, without a size's
 * values or a product's other fields.
 */
final readonly class VariantBriefDto
{
    public function __construct(
        public string $variantId,
        public string $productId,
        public string $productNameAr,
        public ?string $productNameEn,
        public ProductStage $productStage,
        public int $position,
        public bool $isArchived,
    ) {}
}
