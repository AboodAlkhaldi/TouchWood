<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Prices;

use Modules\Platform\Public\Contracts\MenuCount;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\PlatformPermissions;
use Modules\Pricing\Application\PricingPermissions;
use Shared\Application\Authorizer;
use Shared\Domain\ValueObject\StoreId;

/**
 * How many sizes need a price (pricing.md §1.9), beside the Needs a Price entry in the menu: over every
 * store the reader may **Edit Prices and Sales** in - a store that is off only for whoever may switch
 * stores, as the list refuses it to anyone else. Its entry comes with the list's screen (the frontend
 * session), as the menu links every entry to its route.
 */
final readonly class NeedsAPriceCount implements MenuCount
{
    public function __construct(
        private Authorizer $authorizer,
        private PlatformApi $platform,
        private UnpricedSizes $unpriced,
    ) {}

    public function count(): int
    {
        $stores = $this->authorizer->storesWith(PricingPermissions::PRICE_EDIT)
            ?? array_map(static fn ($store): StoreId => $store->storeId(), $this->platform->allStores());
        $mayOff = $this->authorizer->storesWith(PlatformPermissions::STORE_SWITCH) !== [];

        return array_sum(array_map(
            fn (StoreId $store): int => $mayOff || $this->platform->store($store)?->isActive === true ? count($this->unpriced->in($store)) : 0,
            $stores,
        ));
    }
}
