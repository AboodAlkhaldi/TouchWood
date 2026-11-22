<?php

declare(strict_types=1);

namespace Modules\Pricing\Public\Contracts;

use Modules\Pricing\Public\Dto\ProviderPricesResult;
use Shared\Domain\ValueObject\StoreId;

/**
 * **The provider's prices**, written by Sync (pricing.md §2.6; sync.md §1.4, §2.3). Module to module, so
 * no permission is checked here - Sync's read checks its own.
 */
interface ProviderPrices
{
    /**
     * Each size's retail price set to the provider's base price, in a wired store (sync.md §1.4): the
     * same price changes nothing; a different one is saved with source `PROVIDER` and audited from and
     * to, naming the read. A size switched off in the store, or of a draft product, takes it too and is
     * named. **The ceiling**: a running or scheduled sale the new price takes past it stops on that
     * size - the size leaves the sale; a sale left with none ends if running, or is removed if not
     * started (or started this very moment) - audited as the system's; a fixed-amount category
     * discount skips the size while it would go past it (§2.6; sync.md §9.3 #17, #20). Each is named.
     * 500 sizes a transaction, under the store's lock, the wiring asked again in each.
     *
     * Not written, and named for Sync to report: an archived size or one of an archived product, a size
     * that does not exist, a price that is not one in the store's currency - not a number, 0 or below,
     * more decimals than the currency has - and a second id written for the same size. A store
     * unwired before or while the read ran writes nothing from then on: the result says `wired` false.
     *
     * @param  array<string, mixed>  $prices  variant id => the provider's base price as it came: a decimal written as text, in the store's currency - anything else (a JSON number, null) is named invalid, so one bad value never fails the read (sync.md §1.7)
     * @param  string  $readRef  the read's own id: letters, digits, `-` and `_`, at most 64
     */
    public function apply(StoreId $store, array $prices, string $readRef): ProviderPricesResult;
}
