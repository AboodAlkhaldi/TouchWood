<?php

declare(strict_types=1);

namespace Modules\Shipping\Application\Uncovered;

use Modules\Platform\Public\Contracts\MenuCount;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Shipping\Application\ShippingPermissions;
use Shared\Application\Authorizer;
use Shared\Domain\ValueObject\StoreId;

/**
 * The count beside the Carriers entry while some place is served by no carrier (shipping.md §1.6;
 * owner, 2026-10-27 §9.3 #3): over every store the reader may see carriers in. The kept counts, one
 * query; a store not counted yet is counted now and kept.
 */
final readonly class UncoveredPlacesCount implements MenuCount
{
    public function __construct(
        private Authorizer $authorizer,
        private PlatformApi $platform,
        private UncoveredCounts $counts,
        private UncoveredPlaces $places,
    ) {}

    public function count(): int
    {
        $stores = $this->authorizer->storesWith(ShippingPermissions::CARRIERS_VIEW)
            ?? array_map(static fn (StoreDto $store): StoreId => $store->storeId(), $this->platform->allStores());
        $ids = array_map(static fn (StoreId $store): string => $store->value, $stores);
        $kept = $this->counts->of($ids);
        $total = 0;

        foreach ($stores as $store) {
            if (! isset($kept[$store->value])) {
                $kept[$store->value] = $this->places->count($store);
                $this->counts->write($store->value, $kept[$store->value]);
            }

            $total += $kept[$store->value];
        }

        return $total;
    }
}
