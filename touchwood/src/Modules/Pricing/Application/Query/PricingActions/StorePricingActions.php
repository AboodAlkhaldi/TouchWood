<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\PricingActions;

/**
 * **What Pricing's screens may offer in one store** (pricing.md §4.3.1 "Who may do what"): which of the three
 * jobs the reader holds there, whether they may set its discount ceiling (the link under each subtitle,
 * Q11) and open a product's page (`catalog.product.view`), whether the store is wired to a provider or off
 * (a Super Admin's, marked **Off**), the ceiling itself ("The store's discount ceiling: 30%"), and why a
 * retail price can't be typed or removed here though the reader may edit prices - the provider sets it.
 * Offering is never allowing: every handler asks again.
 */
final readonly class StorePricingActions
{
    public function __construct(
        public string $storeId,
        public bool $mayEditPrices,
        public bool $mayEditWholesale,
        public bool $mayManageDiscounts,
        public bool $maySetCeiling,
        public bool $mayViewProducts,
        public bool $wired,
        public bool $off,
        public int $ceilingPercent,
        public ?string $retailPriceRefusal,
    ) {}
}
