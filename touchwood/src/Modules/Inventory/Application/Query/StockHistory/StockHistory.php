<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Query\StockHistory;

/**
 * **One size's ledger in a store** (inventory.md §4.3.3 `StockHistory`), newest first, 50 at a time
 * after the cursor of the page before (Show More in the drawer).
 */
final readonly class StockHistory
{
    public const int PER_PAGE = 50;

    public function __construct(
        public string $storeId,
        public string $variantId,
        public ?string $after = null,
    ) {}
}
