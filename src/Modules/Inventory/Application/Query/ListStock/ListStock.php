<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Query\ListStock;

/**
 * **The Stock screen's rows** (inventory.md §4.3.3 `ListStock`, I1): a store's sizes switched on, and
 * those with a stock row there though switched off - all of them, those **Low**, **Out of Stock**,
 * **Held** by orders, or **Short** - found by a name in either language or a code, 50 at a time after the
 * cursor of the page before.
 */
final readonly class ListStock
{
    public const int PER_PAGE = 50;

    public const string ALL = 'ALL';

    public const string LOW = 'LOW';

    public const string OUT_OF_STOCK = 'OUT_OF_STOCK';

    public const string HELD = 'HELD';

    public const string SHORT = 'SHORT';

    public const array FILTERS = [self::ALL, self::LOW, self::OUT_OF_STOCK, self::HELD, self::SHORT];

    public function __construct(
        public string $storeId,
        public ?string $search = null,
        public string $filter = self::ALL,
        public ?string $after = null,
    ) {}
}
