<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\ListCategoryDiscounts;

/**
 * **The Category Discounts screen's rows** (pricing.md §4.3.3 P3): a store's discounts in one state or
 * any (`SCHEDULED`, `RUNNING`, `ENDED`), by state or newest added (`BY_STATE`, `NEWEST`; Q7, as P2's), 50
 * at a time after the cursor of the page before.
 */
final readonly class ListCategoryDiscounts
{
    public const int PER_PAGE = 50;

    public function __construct(
        public string $storeId,
        public ?string $state = null,
        public string $order = 'BY_STATE',
        public ?string $after = null,
    ) {}
}
