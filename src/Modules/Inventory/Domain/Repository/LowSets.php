<?php

declare(strict_types=1);

namespace Modules\Inventory\Domain\Repository;

use DateTimeImmutable;
use Modules\Inventory\Domain\ValueObject\LowChanges;
use Modules\Inventory\Domain\ValueObject\LowSet;
use Shared\Domain\ValueObject\StoreId;

/**
 * Each store's low set and the log of its changes (inventory.md §1.7, §5 `inventory.low_sets`,
 * `inventory.low_changes`; amendment 1).
 */
interface LowSets
{
    /**
     * Takes the store's low-set lock - its own, never the stock lock - for the rest of the caller's
     * transaction, so recounts of a store run one at a time; then answers the set kept, or null when the
     * store has none yet. Refused outside a transaction.
     */
    public function lock(StoreId $store): ?LowSet;

    public function save(StoreId $store, LowSet $set, DateTimeImmutable $countedAt): void;

    /** Every change, at the recount's moment - one statement however many. */
    public function record(StoreId $store, LowChanges $changes, DateTimeImmutable $changedAt): void;

    /**
     * The changes in `[from, to)`: each list the sizes whose change of that kind happened there, each
     * once, in id order. One query.
     */
    public function changes(StoreId $store, DateTimeImmutable $from, DateTimeImmutable $to): LowChanges;

    /**
     * @return int how many entries older than `$before` went
     */
    public function prune(DateTimeImmutable $before): int;
}
