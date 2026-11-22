<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\FindSaleProducts;

/**
 * **The products an offer can be put on** (pricing.md §4.3.3 P2, the offer dialog's Product; Q13): the
 * store's ready products with a size switched on there, found by a name in either language or a code, 20
 * at a time after the cursor of the page before.
 */
final readonly class FindSaleProducts
{
    public const int PER_PAGE = 20;

    public function __construct(
        public string $storeId,
        public ?string $search = null,
        public ?string $after = null,
    ) {}
}
