<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Prices;

use Modules\Platform\Public\Contracts\MenuCount;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\PlatformPermissions;
use Modules\Pricing\Application\PricingPermissions;
use Modules\Pricing\Domain\Repository\UnpricedCounts;
use Shared\Application\Authorizer;
use Shared\Domain\ValueObject\StoreId;

/**
 * How many sizes need a price (pricing.md §1.9), beside the Needs a Price entry in the menu: over every
 * store the reader may **Edit Prices and Sales** in - a store that is off only for whoever may switch
 * stores, as the list refuses it to anyone else. Each store's count is kept (`pricing.unpriced_counts`;
 * owner Q43, 2026-10-19, as Low Stock's), counted again after every change that can move it - about half
 * a minute behind the list at most. Its entry comes with the list's screen (step 3).
 */
final readonly class NeedsAPriceCount implements MenuCount
{
    public function __construct(
        private Authorizer $authorizer,
        private PlatformApi $platform,
        private UnpricedSizes $unpriced,
        private UnpricedCounts $counts,
        private UnpricedRecounts $recounts,
    ) {}

    /**
     * The kept counts, one query. A store not counted yet is counted live, and its count queued, until
     * that first count lands.
     */
    public function count(): int
    {
        $mayOff = $this->authorizer->storesWith(PlatformPermissions::STORE_SWITCH) !== [];
        $stores = array_values(array_filter(
            $this->authorizer->storesWith(PricingPermissions::PRICE_EDIT) ?? array_map(static fn ($store): StoreId => $store->storeId(), $this->platform->allStores()),
            fn (StoreId $store): bool => $mayOff || $this->platform->store($store)?->isActive === true,
        ));
        $kept = $this->counts->of($stores);
        $total = 0;

        foreach ($stores as $store) {
            if (isset($kept[$store->value])) {
                $total += $kept[$store->value];

                continue;
            }

            $total += $this->unpriced->count($store);
            $this->recounts->queue($store);
        }

        return $total;
    }
}
