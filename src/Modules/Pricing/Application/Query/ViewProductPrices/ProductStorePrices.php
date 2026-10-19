<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\ViewProductPrices;

/**
 * **Pricing's part of one store card** on a product's Stores tab (pricing.md §4.3.3): which of the three
 * jobs the reader holds there - a box for **Edit Prices and Sales** where the store is not wired to a
 * provider, "3 bands" opening P4's dialog for **Edit Wholesale Prices** - whether it is wired, each size's
 * prices, the product's sales and the discounts reaching it.
 */
final readonly class ProductStorePrices
{
    /**
     * @param  list<ProductSizePrices>  $sizes  in the product's order
     * @param  list<ProductSale>  $sales
     * @param  list<ProductDiscount>  $discounts
     */
    public function __construct(
        public string $storeId,
        public bool $mayEditPrices,
        public bool $mayEditWholesale,
        public bool $mayManageDiscounts,
        public bool $wired,
        public array $sizes,
        public array $sales,
        public array $discounts,
    ) {}
}
