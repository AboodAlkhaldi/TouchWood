<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\ListSaleProducts;

/**
 * **The Offers screen's product filter** (pricing.md §4.3.3 P2): the products with a sale in the store,
 * any state, found by a name or a code, by name, 50 at a time after the cursor of the page before.
 */
final readonly class ListSaleProducts
{
    public const int PER_PAGE = 50;

    public function __construct(
        public string $storeId,
        public ?string $search = null,
        public ?string $after = null,
    ) {}
}
