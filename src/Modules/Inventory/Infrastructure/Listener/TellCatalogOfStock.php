<?php

declare(strict_types=1);

namespace Modules\Inventory\Infrastructure\Listener;

use Illuminate\Database\ConnectionInterface;
use Modules\Catalog\Public\Events\StoreListingChanged;
use Modules\Inventory\Application\InventorySettings;
use Modules\Inventory\Application\Stock\StockFacts;
use Modules\Inventory\Domain\Repository\InventoryLocks;
use Modules\Inventory\Domain\Repository\StockRows;
use Modules\Platform\Public\Events\SettingChanged;
use Shared\Domain\ValueObject\StoreId;

/**
 * What Catalog is told when something outside Inventory changes a size's figures (inventory.md §2.3,
 * §6.2): sizes a store switched on - told even with no stock row, as 0 - and every size of a store
 * whose default threshold changed, which may flip "last pieces". Each in its own transaction under
 * the store's lock, after the other module's change commits.
 */
final readonly class TellCatalogOfStock
{
    public function __construct(
        private StockFacts $facts,
        private StockRows $stock,
        private InventoryLocks $locks,
        private ConnectionInterface $db,
    ) {}

    public function switchedOn(StoreListingChanged $event): void
    {
        $this->inStore(StoreId::fromString($event->storeId), fn (StoreId $store) => $this->facts->refresh($store, $event->variantIds, all: true));
    }

    public function settingChanged(SettingChanged $event): void
    {
        if ($event->key !== InventorySettings::LOW_STOCK_DEFAULT || $event->storeId === null) {
            return;
        }

        $this->inStore(StoreId::fromString($event->storeId), fn (StoreId $store) => $this->facts->refresh($store, $this->stock->variantIds($store)));
    }

    /**
     * @param  callable(StoreId): int  $work
     */
    private function inStore(StoreId $store, callable $work): void
    {
        $this->db->transaction(function () use ($store, $work): void {
            $this->locks->lockStore($store);
            $work($store);
        }, 3);
    }
}
