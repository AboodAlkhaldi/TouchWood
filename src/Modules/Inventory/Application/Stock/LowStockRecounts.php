<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Stock;

use Shared\Domain\ValueObject\StoreId;

/**
 * A store's Low Stock count queued to be counted again (step 5), after the change commits - one waiting
 * per store, however many changes come before it runs.
 */
interface LowStockRecounts
{
    public function queue(StoreId $store): void;

    /** Every store: a size archived or restored may be low in any of them. */
    public function queueAll(): void;
}
