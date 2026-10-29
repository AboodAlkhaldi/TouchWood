<?php

declare(strict_types=1);

namespace Modules\Inventory\Application\Command\CountLowStock;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;
use Modules\Inventory\Application\InventoryPermissions;
use Modules\Inventory\Application\Stock\LowStockReader;
use Modules\Inventory\Domain\Repository\LowCounts;
use Modules\Inventory\Domain\Repository\LowSets;
use Modules\Inventory\Public\Events\LowStockChanged;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;

/**
 * **The Low Stock count kept per store** (owner, 2026-10-18; inventory.md §1.7): the store's low sizes
 * counted with the list's own reader - so the count, the list and the Home card never disagree - and
 * kept for the menu. Queued after every change that can move it, and each night for every store.
 *
 * **And what changed since the last recount** (amendment 1, for Ops): the store's low set kept, compared
 * with the one kept before - became low, ran out, came back - written to the change log and sent as one
 * `LowStockChanged`, after commit, when anything changed. A store's first recount keeps its set silently.
 * Recounts of a store run one at a time under its low-set lock, the read inside it: a recount that takes
 * the lock later reads later, so nothing is said twice or lost.
 *
 * Changes nothing a person did, so it audits nothing; answers how many stores were counted.
 */
final readonly class CountLowStockHandler
{
    /** Reserved: the system's. */
    public const string PERMISSION = InventoryPermissions::LOW_STOCK_COUNT;

    public function __construct(
        private Authorizer $authorizer,
        private PlatformApi $platform,
        private LowStockReader $reader,
        private LowCounts $counts,
        private LowSets $sets,
        private ConnectionInterface $db,
        private Dispatcher $events,
    ) {}

    /**
     * @throws Unauthorized
     */
    public function handle(CountLowStock $command): int
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());

        $stores = $command->storeId === null
            ? array_map(static fn ($store): StoreId => $store->storeId(), $this->platform->allStores())
            : [StoreId::fromString($command->storeId)];

        // Started before counting: a change made while it counts queues another, never lost.
        if ($command->storeId === null) {
            $this->counts->allRecountsStart();
        } else {
            $this->counts->recountStarts($stores[0]);
        }

        foreach ($stores as $store) {
            // A store gone since the job was queued has nothing to count.
            if ($this->platform->store($store) !== null) {
                $this->db->transaction(fn () => $this->recount($store), 3);
            }
        }

        return count($stores);
    }

    /**
     * One store, in one transaction: its low-set lock first, then the read, the count, the set, the log and
     * the event. Self-contained, as a retried transaction needs (lesson 57).
     */
    private function recount(StoreId $store): void
    {
        $kept = $this->sets->lock($store);
        // Its start, not its end: a count started later, and kept first, is the newer one.
        $countedAt = CarbonImmutable::now()->toDateTimeImmutable();
        $recount = $this->reader->recount($store, $kept === null ? [] : [...$kept->low, ...$kept->out]);
        $now = $recount->set();

        $this->counts->save($store, count($recount->sizes), $countedAt);
        $this->sets->save($store, $now, $countedAt);

        // The first recount of a store keeps its set and says nothing: the deploy sends no flood (§1.7).
        if ($kept === null) {
            return;
        }

        $changes = $kept->changesTo($now, $recount->gone);

        if ($changes->isEmpty()) {
            return;
        }

        $this->sets->record($store, $changes, $countedAt);
        $this->events->dispatch(new LowStockChanged((string) Str::uuid(), $store->value, $changes->becameLow, $changes->ranOut, $changes->cameBack, $countedAt));
    }
}
