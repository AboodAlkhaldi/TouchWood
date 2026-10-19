<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\ListSales;

/**
 * **The Offers screen's sales** (pricing.md §4.3.3 P2): a store's sales in one state or any (`SCHEDULED`,
 * `RUNNING`, `ENDED`), of one product, or the one sale a link names (`?sale=`); **by state** (the default)
 * or **newest added** (`BY_STATE`, `NEWEST`; owner Q7); 50 at a time after the cursor of the page before.
 */
final readonly class ListSales
{
    public const int PER_PAGE = 50;

    public function __construct(
        public string $storeId,
        public ?string $state = null,
        public ?string $productId = null,
        public ?string $saleId = null,
        public string $order = 'BY_STATE',
        public ?string $after = null,
    ) {}
}
