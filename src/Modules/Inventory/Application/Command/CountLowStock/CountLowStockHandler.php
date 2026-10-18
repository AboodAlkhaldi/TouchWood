<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Command\CountLowStock;

use Modules\Inventory\Application\InventoryPermissions;
use Modules\Inventory\Application\Stock\LowStockReader;
use Modules\Inventory\Domain\Repository\LowCounts;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * **The Low Stock count kept per store** (owner, 2026-10-18; inventory.md §1.7): the store's low sizes
 * counted with the list's own reader - so the count, the list and the Home card never disagree - and
 * kept for the menu. Queued after every change that can move it, and each night for every store.
 * Changes nothing a person did, so it audits nothing; answers how many stores were counted.
 */
final readonly class CountLowStockHandler
{
    /** Reserved: the system's. */
    public const string PERMISSION = InventoryPermissions::LOW_STOCK_COUNT;

    public function __construct(
        private Authorizer $authorizer,
        private PlatformApi $platform,
        private LowStockReader $reader,
        private LowCounts $counts,
    ) {}

    /**
     * @throws Unauthorized
     */
    public function handle(CountLowStock $command): int
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());

        $stores = $command->storeId === null
            ? array_map(static fn ($store): StoreId => $store->storeId(), $this->platform->allStores())
            : [StoreId::fromString($command->storeId)];

        // Started before counting: a change made while it counts queues another, never lost.
        if ($command->storeId === null) {
            $this->counts->allRecountsStart();
        } else {
            $this->counts->recountStarts($stores[0]);
        }

        foreach ($stores as $store) {
            // A store gone since the job was queued has nothing to count.
            if ($this->platform->store($store) !== null) {
                $this->counts->save($store, count($this->reader->low($store)));
            }
        }

        return count($stores);
    }
}
