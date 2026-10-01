<?php

declare(strict_types=1);

namespace Modules\B2B\Domain\Repository;

use Modules\B2B\Domain\Model\StoreTypeLists;

/**
 * Each store's "copied, not yet reviewed" flag over its two type lists (b2b.md amendment 6(a)):
 * one row per store, once its lists have been written.
 */
interface StoreTypeListsRepository
{
    /** A read with no lock. */
    public function find(string $storeId): ?StoreTypeLists;

    /** Locks the row: for a change, inside its transaction. */
    public function byStore(string $storeId): ?StoreTypeLists;

    /**
     * Serialises every change to one store's two lists, inside the caller's transaction: the first
     * step of B2B's one lock order — the store's lists, then an account, then its company (README).
     * An advisory lock, since a store need not have a row here.
     */
    public function lockLists(string $storeId): void;

    public function add(StoreTypeLists $lists): void;

    public function update(StoreTypeLists $lists): void;
}
