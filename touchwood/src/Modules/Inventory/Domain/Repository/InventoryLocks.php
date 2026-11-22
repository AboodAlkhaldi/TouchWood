<?php

declare(strict_types=1);

namespace Modules\Inventory\Domain\Repository;

use Shared\Domain\ValueObject\StoreId;

/**
 * One lock per store for every hand change there (inventory.md §3): taken first, inside the change's
 * own transaction, so two changes in a store never read what the other is about to write.
 */
interface InventoryLocks
{
    public function lockStore(StoreId $store): void;
}
