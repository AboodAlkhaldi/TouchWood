<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Query\Shop;

/**
 * A variant a shopper can order in this store — **never its code** (amendment 5(d)) — with what its
 * page shows once it is chosen (catalog.md §4.5): its own photos, its details, its weight and size,
 * how it is sold here, and **"Last pieces"** while it is ending soon and can be ordered now
 * (amendment 16(h)). **Its price** (amendment 35): what one piece costs now, and the price before only
 * while now is lower, in the store's smallest unit, without VAT — none until Pricing pushes one.
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
        public ?int $priceMinor = null,
        public ?int $priceBeforeMinor = null,
    ) {}
}
