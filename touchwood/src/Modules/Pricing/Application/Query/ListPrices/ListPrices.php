<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\ListPrices;

/**
 * **The Prices screen's rows** (pricing.md §4.3.3 P1): a store's sizes switched on, and those with a price
 * there though switched off - all of them, those with **No Price**, those **On Sale Now**, or those that
 * **Have Wholesale Prices** - found by a name in either language or a code, 50 at a time after the cursor
 * of the page before.
 */
final readonly class ListPrices
{
    public const int PER_PAGE = 50;

    public const string ALL = 'ALL';

    public const string NO_PRICE = 'NO_PRICE';

    public const string ON_SALE_NOW = 'ON_SALE_NOW';

    public const string HAS_WHOLESALE = 'HAS_WHOLESALE';

    public const array FILTERS = [self::ALL, self::NO_PRICE, self::ON_SALE_NOW, self::HAS_WHOLESALE];

    public function __construct(
        public string $storeId,
        public ?string $search = null,
        public string $filter = self::ALL,
        public ?string $after = null,
    ) {}
}
