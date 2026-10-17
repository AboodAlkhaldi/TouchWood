<?php

declare(strict_types=1);

namespace Modules\Inventory\Public\Contracts;

use Modules\Inventory\Public\Dto\ProviderStockResult;
use Shared\Domain\ValueObject\StoreId;

/**
 * **The provider's stock**, written by Sync (inventory.md §2.5; sync.md §1.2, §1.5, §1.8, §2.3). Module
 * to module, so no permission is checked here - Sync's read checks its own. Nothing here is audited:
 * Sync audits each read once, the detail being in the ledger (sync.md §1.6).
 */
interface ProviderStock
{
    /**
     * Each size's in stock set to the provider's number, in a wired store: the same number as now
     * changes nothing (a size with no row at 0 gets none); a different one is a `PROVIDER` movement to
     * it, its reference made of `$readRef` and the size, so the same read run again is counted once;
     * the size's returned extra goes back to 0; orderable and "last pieces" are pushed to Catalog. A
     * size switched off in the store, or of a draft product, takes it too. 500 sizes a transaction,
     * under the store's lock, the wiring asked again in each.
     *
     * Not written, and named in the result for Sync to report: an archived size or one of an archived
     * product (read-only until restored), a size that does not exist, a number below 0 or above the
     * most pieces a size holds (Sync drops fractions and keeps a number below 0 as 0 first, sync.md
     * §1.5 #2), a second id written for the same size. A store unwired before or while the read ran
     * writes nothing from then on: the result says `wired` false.
     *
     * Sync's part: `$readRef` is the read's own id, never used for another read or store, and a read
     * runs once per store at a time, its retry asking the provider again (sync.md §1.3, §1.7) - an old
     * read's numbers are never applied after a later read's.
     *
     * @param  array<string, int>  $stock  variant id => the provider's number
     * @param  string  $readRef  the read's own id: letters, digits, `-` and `_`, at most 64
     */
    public function apply(StoreId $store, array $stock, string $readRef): ProviderStockResult;

    /**
     * The store has just been wired (sync.md §1.2; Sync calls this once `StoreWiring` answers true):
     * every size switched on there or with a row is told to Catalog again for a wired store - an
     * ordinary product orderable whatever its number (§1.3). Nothing is written.
     */
    public function wired(StoreId $store): void;

    /**
     * How many sizes switched on in the store would stop being orderable once it is unwired, for the
     * Super Admin's confirmation (sync.md §1.8): with no provider every product counts on its stock,
     * so a size orderable now with nothing free then stops. Reads only.
     */
    public function stopsWhenUnwired(StoreId $store): int;

    /**
     * The store has just been unwired (sync.md §1.8; Sync calls this once `StoreWiring` answers
     * false): each size's returned extra is added into its in stock - a `CORRECTION` movement noted
     * "unwired" (sync.md §9.2 #12) - and every size's orderability is worked out again for a store
     * with no provider. Nothing else is cleared. Answers how many sizes switched on there stopped
     * being orderable.
     */
    public function unwired(StoreId $store): int;
}
