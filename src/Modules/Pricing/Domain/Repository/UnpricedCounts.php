<?php

declare(strict_types=1);

namespace Modules\Pricing\Domain\Repository;

use DateTimeImmutable;
use Shared\Domain\ValueObject\StoreId;

/**
 * Each store's count of sizes that need a price, kept for the menu (pricing.md §1.9, §5
 * `pricing.unpriced_counts`; owner Q43, 2026-10-19 - as Inventory's Low Stock count).
 */
interface UnpricedCounts
{
    /** Kept unless a count started later is kept already: an older count never overwrites a newer one. */
    public function save(StoreId $store, int $unpriced, DateTimeImmutable $countedAt): void;

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
