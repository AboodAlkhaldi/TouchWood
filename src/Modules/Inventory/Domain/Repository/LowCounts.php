<?php

declare(strict_types=1);

namespace Modules\Inventory\Domain\Repository;

use Shared\Domain\ValueObject\StoreId;

/**
 * Each store's count of low sizes, kept for the menu (inventory.md §1.7, §5 `inventory.low_counts`).
 */
interface LowCounts
{
    public function save(StoreId $store, int $low): void;

    /** Marks the store's recount queued - true for whoever marks it first, false while one already waits. */
    public function claimRecount(StoreId $store): bool;

    /** The store's waiting recount starting: a change from now on queues another. */
    public function recountStarts(StoreId $store): void;

    /** Every store's waiting recount starting (the night's count of every store). */
    public function allRecountsStart(): void;

    /**
     * @param  list<StoreId>  $stores
     * @return array<string, int> by store id; a store not counted yet is not there - one query
     */
    public function of(array $stores): array;
}
