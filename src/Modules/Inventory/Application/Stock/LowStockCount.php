<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Stock;

use Modules\Inventory\Application\InventoryPermissions;
use Modules\Inventory\Domain\Repository\LowCounts;
use Modules\Platform\Public\Contracts\MenuCount;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Domain\ValueObject\StoreId;

/**
 * How many sizes are low (inventory.md §1.7), beside the Low Stock entry in the menu: over every store
 * the reader holds **Manage Stock** in. Asked only for someone the entry is offered to. The entry itself
 * comes with the Low Stock screen (the frontend session), as the menu links every entry to its route.
 * Each store's count is kept (`inventory.low_counts`), counted again after every change that can move
 * it - a few seconds behind the list at most.
 */
final readonly class LowStockCount implements MenuCount
{
    public function __construct(
        private Authorizer $authorizer,
        private PlatformApi $platform,
        private LowStockReader $reader,
        private LowCounts $counts,
        private LowStockRecounts $recounts,
    ) {}

    /**
     * The kept counts, one query (owner, 2026-10-18). A store not counted yet is counted live, and its
     * count queued, until that first count lands - about half a minute after it is first needed.
     */
    public function count(): int
    {
        $stores = $this->reader->shown($this->authorizer->storesWith(InventoryPermissions::STOCK_MANAGE)
            ?? array_map(static fn ($store): StoreId => $store->storeId(), $this->platform->allStores()));
        $kept = $this->counts->of($stores);
        $total = 0;

        foreach ($stores as $store) {
            if (isset($kept[$store->value])) {
                $total += $kept[$store->value];

                continue;
            }

            $total += count($this->reader->low($store));
            $this->recounts->queue($store);
        }

        return $total;
    }
}
