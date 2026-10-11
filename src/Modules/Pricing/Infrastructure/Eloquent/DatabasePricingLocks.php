<?php

declare(strict_types=1);

namespace Modules\Pricing\Infrastructure\Eloquent;

use Illuminate\Database\ConnectionInterface;
use LogicException;
use Modules\Pricing\Domain\Repository\PricingLocks;
use Shared\Domain\ValueObject\StoreId;

/**
 * A transaction-scoped advisory lock per store (as Catalog's list locks, lesson 64): released by the
 * commit or the rollback, so no path can forget it, and refused outside a transaction, where it would
 * be released at once and guard nothing.
 */
final readonly class DatabasePricingLocks implements PricingLocks
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function lockStore(StoreId $store): void
    {
        if ($this->db->transactionLevel() === 0) {
            throw new LogicException("The store's pricing lock is taken inside the change's transaction.");
        }

        $this->db->select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['pricing:store:'.$store->value], false);
    }
}
