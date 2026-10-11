<?php

declare(strict_types=1);

namespace Modules\Inventory\Infrastructure\Eloquent;

use Illuminate\Database\ConnectionInterface;
use LogicException;
use Modules\Inventory\Domain\Repository\InventoryLocks;
use Shared\Domain\ValueObject\StoreId;

/**
 * A transaction-scoped advisory lock per store (as Pricing's and Catalog's, lesson 64): released by
 * the commit or the rollback, so no path can forget it, and refused outside a transaction, where it
 * would be released at once and guard nothing.
 */
final readonly class DatabaseInventoryLocks implements InventoryLocks
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function lockStore(StoreId $store): void
    {
        if ($this->db->transactionLevel() === 0) {
            throw new LogicException("The store's inventory lock is taken inside the change's transaction.");
        }

        $this->db->select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['inventory:store:'.$store->value], false);
    }
}
