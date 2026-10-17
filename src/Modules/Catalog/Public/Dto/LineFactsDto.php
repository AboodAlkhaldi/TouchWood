<?php

declare(strict_types=1);

namespace Modules\Catalog\Public\Dto;

/**
 * What a cart line's variant belongs to (catalog.md §2.1, amendment 18): its product, the product's
 * brand, and the product's category with **every category above it**, nearest first — so a coupon
 * that covers "Kitchens" covers its sub-categories (promotions.md §1.2). A product with no category
 * has none.
 */
final readonly class LineFactsDto
{
    /**
     * @param  list<string>  $categoryIds  the product's own category first, then each one above it
     */
    public function __construct(
        public string $variantId,
        public string $productId,
        public string $brandId,
        public array $categoryIds,
    ) {}
}
