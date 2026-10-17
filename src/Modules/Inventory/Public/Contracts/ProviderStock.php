<?php

declare(strict_types=1);

namespace Modules\Inventory\Public\Contracts;

use Modules\Inventory\Public\Dto\ProviderStockResult;
use Shared\Domain\ValueObject\StoreId;

/**
 * **The provider's stock**, written by Sync (inventory.md §2.5; sync.md §1.5, §1.8, §2.3). Module to
 * module, so no permission is checked here - Sync's read checks its own. Nothing here is audited: Sync
 * audits each read once, the detail being in the ledger (sync.md §1.6).
 */
interface ProviderStock
{
    /**
     * Each size's in stock set to the provider's number, in a wired store: the same number as now
     * changes nothing; a different one is a `PROVIDER` movement to it, its reference made of
     * `$readRef` and the size, so a read run again is counted once; the size's returned extra goes
     * back to 0; orderable and "last pieces" are pushed to Catalog. A size switched off in the store,
     * or of a draft product, takes it too. 500 sizes a transaction, under the store's lock.
     *
     * Not written, and named in the result for Sync to report: an archived size or one of an archived
     * product (read-only until restored), a size that does not exist, a number below 0 or above the
     * most pieces a size holds (Sync drops fractions and keeps a number below 0 as 0 first, sync.md
     * §1.5 #2). A store that is not wired - unwired while the read ran - writes nothing.
     *
     * @param  array<string, int>  $stock  variant id => the provider's number
     * @param  string  $readRef  the read's own id: letters, digits, `-` and `_`, at most 64
     */
    public function apply(StoreId $store, array $stock, string $readRef): ProviderStockResult;

    /**
     * How many sizes switched on in the store would stop being orderable once it is unwired, for the
     * Super Admin's confirmation (sync.md §1.8): with no provider every product counts on its stock,
     * so an ordinary product the provider left at 0 stops. Reads only.
     */
    public function stopsWhenUnwired(StoreId $store): int;

    /**
     * The store has just been unwired (sync.md §1.8; Sync calls this once `StoreWiring` answers
     * false): each size's returned extra is added into its in stock - a `RETURNED` movement - and
     * every size's orderability is worked out again for a store with no provider. Nothing else is
     * cleared. Answers how many sizes switched on there stopped being orderable.
     */
    public function unwired(StoreId $store): int;
}
