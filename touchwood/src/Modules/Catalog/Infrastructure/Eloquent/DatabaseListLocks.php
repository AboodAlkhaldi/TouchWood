<?php

declare(strict_types=1);

namespace Modules\Catalog\Infrastructure\Eloquent;

use Illuminate\Database\ConnectionInterface;
use LogicException;
use Modules\Catalog\Application\Query\Shop\ShopPagesCache;
use Modules\Catalog\Domain\Repository\ListLocks;

/**
 * A transaction-scoped advisory lock per shared list (as B2B's type lists, lesson 64): released by
 * the commit or the rollback, so no path can forget it, and refused outside a transaction, where it
 * would be released at once and guard nothing.
 *
 * **Every change Catalog makes takes one**, so taking one is where the shop's cached pages are told
 * (catalog.md §4.5, P21): their version is replaced inside the change's transaction — and **before**
 * the advisory lock, so every change meets the version's row first and in the same order, and two
 * changes never wait on each other in a circle (one holding a list's lock while waiting for the
 * version, the other the other way round). The word pairs' lock is the one that does not: they change
 * only what a search finds, which is never kept.
 *
 * The price: every Catalog change now also waits on that one row while another change holds it, as
 * every product change already waited on the products' lock - a long import step holds both. A path
 * that locks another row before its first list lock (deleting a photo, which locks the media row,
 * then asks Catalog to let go of it) meets the version where it met the products' lock before.
 */
final readonly class DatabaseListLocks implements ListLocks
{
    public function __construct(
        private ConnectionInterface $db,
        private ShopPagesCache $shopPages,
    ) {}

    public function lock(string $list): void
    {
        if ($this->db->transactionLevel() === 0) {
            throw new LogicException("The {$list} lock is taken inside the change's transaction.");
        }

        if ($list !== ListLocks::WORD_PAIRS) {
            $this->shopPages->invalidate();
        }

        $this->db->select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['catalog:'.$list], false);
    }
}
