<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Stock;

use Modules\Inventory\Application\InventoryPermissions;
use Modules\Platform\Public\Contracts\MenuCount;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Domain\ValueObject\StoreId;

/**
 * How many sizes are low (inventory.md §1.7), beside the Low Stock entry in the menu: over every store
 * the reader holds **Manage Stock** in. Asked only for someone the entry is offered to. The entry itself
 * comes with the Low Stock screen (the frontend session), as the menu links every entry to its route.
 */
final readonly class LowStockCount implements MenuCount
{
    public function __construct(
        private Authorizer $authorizer,
        private PlatformApi $platform,
        private LowStockReader $reader,
    ) {}

    public function count(): int
    {
        $stores = $this->authorizer->storesWith(InventoryPermissions::STOCK_MANAGE)
            ?? array_map(static fn ($store): StoreId => $store->storeId(), $this->platform->allStores());

        return array_sum(array_map(fn (StoreId $store): int => count($this->reader->low($store)), $stores));
    }
}
