<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Prices;

use Shared\Domain\ValueObject\StoreId;

/**
 * A store's Needs a Price count queued to be counted again (owner Q43), after the change commits - one
 * waiting per store, however many changes come before it runs. As Inventory's `LowStockRecounts`.
 */
interface UnpricedRecounts
{
    public function queue(StoreId $store): void;

    /** Every store: a size archived or restored may need a price in any of them. */
    public function queueAll(): void;
}
