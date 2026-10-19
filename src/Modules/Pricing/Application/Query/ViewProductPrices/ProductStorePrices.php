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
     * @param  int  $decimals  the store's currency's decimals, for a price box's check as typed (stage 5's screens step 3)
     * @param  string  $currency  its letters in the page's language, after a price box (its abbreviation)
     * @param  string  $timezone  the store's zone, for its moments
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
        public int $decimals = 2,
        public string $currency = '',
        public string $timezone = 'UTC',
    ) {}
}
