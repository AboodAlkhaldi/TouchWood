<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Command\RebuildOrderable;

use Illuminate\Database\ConnectionInterface;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Inventory\Application\InventoryPermissions;
use Modules\Inventory\Application\Stock\StockFacts;
use Modules\Inventory\Domain\Repository\InventoryLocks;
use Modules\Inventory\Domain\Repository\StockRows;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;

/**
 * **The repair** (inventory.md §3): in every store, on or off, every size it has switched on or holds
 * stock of is told to Catalog again - whether it can be ordered and whether it is "ending soon" - a
 * size with no stock row as 0. One transaction per store under its lock, 500 sizes a push. Changes
 * nothing a person did, so it audits nothing; answers how many sizes were told.
 */
final readonly class RebuildOrderableHandler
{
    /** Reserved: the system's, or a Super Admin's. */
    public const string PERMISSION = InventoryPermissions::ORDERABLE_REBUILD;

    public function __construct(
        private Authorizer $authorizer,
        private PlatformApi $platform,
        private CatalogApi $catalog,
        private StockRows $stock,
        private InventoryLocks $locks,
        private StockFacts $facts,
        private ConnectionInterface $db,
    ) {}

    /**
     * @throws Unauthorized
     */
    public function handle(RebuildOrderable $command): int
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $told = 0;

        foreach ($this->platform->allStores() as $store) {
            $told += $this->db->transaction(function () use ($store): int {
                $storeId = $store->storeId();
                $this->locks->lockStore($storeId);
                $sizes = array_values(array_unique([...$this->stock->variantIds($storeId), ...$this->catalog->switchedOnVariantIds($storeId)]));
                sort($sizes);
                $told = 0;

                foreach (array_chunk($sizes, 500) as $chunk) {
                    $told += $this->facts->refresh($storeId, $chunk, all: true);
                }

                return $told;
            }, 3);
        }

        return $told;
    }
}
