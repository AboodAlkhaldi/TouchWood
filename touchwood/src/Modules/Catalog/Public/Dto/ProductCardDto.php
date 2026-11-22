<?php

declare(strict_types=1);

namespace Modules\Catalog\Public\Dto;

use Modules\Catalog\Public\Enums\ProductStage;

/**
 * **A product as Feedback's pages show it** (catalog.md amendment 26(a)): whatever became of it — a
 * wishlist keeps what a store stopped selling, shown as unavailable (feedback.md §1.4) — its name and
 * address in one language, its first photo, and whether the store asked about sells it now (the rule
 * of `productsSoldIn`).
 */
final readonly class ProductCardDto
{
    public function __construct(
        public string $productId,
        public string $name,
        public ?string $slug,
        public ?string $photoMediaId,
        public ProductStage $stage,
        public bool $sold,
        /** Its brand's name in the page's language, and its unit of sale (shop-design.md §7, §10.7). */
        public ?string $brand = null,
        public string $unit = 'PIECE',
    ) {}
}
