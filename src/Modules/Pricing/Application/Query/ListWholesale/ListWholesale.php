<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\ListWholesale;

/**
 * **The Wholesale Prices screen's rows** (pricing.md §4.3.3 P4): the sizes with bands in a store, found by
 * a name in either language or a code, 50 at a time after the cursor of the page before.
 */
final readonly class ListWholesale
{
    public const int PER_PAGE = 50;

    public function __construct(
        public string $storeId,
        public ?string $search = null,
        public ?string $after = null,
    ) {}
}
