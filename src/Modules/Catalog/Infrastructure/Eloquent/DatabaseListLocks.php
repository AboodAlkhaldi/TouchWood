<?php

declare(strict_types=1);

namespace Modules\Catalog\Infrastructure\Eloquent;

use Illuminate\Database\ConnectionInterface;
use LogicException;
use Modules\Catalog\Domain\Repository\ListLocks;

/**
 * A transaction-scoped advisory lock per shared list (as B2B's type lists, lesson 64): released by
 * the commit or the rollback, so no path can forget it, and refused outside a transaction, where it
 * would be released at once and guard nothing.
 */
final readonly class DatabaseListLocks implements ListLocks
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function lock(string $list): void
    {
        if ($this->db->transactionLevel() === 0) {
            throw new LogicException("The {$list} lock is taken inside the change's transaction.");
        }

        $this->db->select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['catalog:'.$list], false);
    }
}
