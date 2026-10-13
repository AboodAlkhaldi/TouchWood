<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\Shop;

/**
 * A variant a shopper can order in this store — **never its code** (amendment 5(d)) — with what its
 * page shows once it is chosen (catalog.md §4.5): its own photos, its details, its weight and size,
 * how it is sold here, and **"Last pieces"** while it is ending soon and can be ordered now
 * (amendment 16(h)).
 */
final readonly class ShopVariant
{
    /**
     * @param  list<VariantChoice>  $values  in the product's order of its variant attributes
     * @param  list<array<string, array<string, string>>>  $photos  its own ready photos, in order
     * @param  list<VariantDetail>  $details  in the attributes' order
     */
    public function __construct(
        public string $variantId,
        public array $values,
        public bool $sellsRetail = true,
        public bool $sellsWholesale = false,
        public bool $lastPieces = false,
        public array $photos = [],
        public array $details = [],
        public ?int $weightGrams = null,
        public ?int $lengthMm = null,
        public ?int $widthMm = null,
        public ?int $heightMm = null,
    ) {}
}
