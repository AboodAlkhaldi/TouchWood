<?php

declare(strict_types=1);

namespace Modules\Shipping\Application\Uncovered;

use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\PlaceDto;
use Modules\Platform\Public\Enums\PlaceKind;
use Modules\Shipping\Application\Price\PriceReader;
use Shared\Domain\ValueObject\StoreId;

/**
 * "Places no carrier serves" (shipping.md §1.6): in each country the store ships to, the biggest places
 * no switched-on carrier serves any part of (owner, 2026-10-29, gap 4.3). A region with nothing in it
 * served is one row; a city is a row only when its region is partly served; a district only when its
 * city is partly served. A place is served by its own price or by one above it (district, city,
 * region - the most specific wins). Retired places are in no list.
 */
final readonly class UncoveredPlaces
{
    public function __construct(
        private PlatformApi $platform,
        private PriceReader $prices,
    ) {}

    /**
     * @return list<PlaceDto>
     */
    public function in(string $storeId, string $countryCode): array
    {
        $priced = array_flip($this->prices->pricedPlaces($storeId, $countryCode));
        $regions = [];
        $cities = [];
        $districts = [];

        foreach ($this->platform->placesOf($countryCode) as $place) {
            match ($place->kind) {
                PlaceKind::Region => $regions[] = $place,
                PlaceKind::City => $cities[(string) $place->regionId][] = $place,
                PlaceKind::District => $districts[(string) $place->cityId][] = $place,
            };
        }

        $rows = [];

        foreach ($regions as $region) {
            if (isset($priced[$region->id])) {
                continue;
            }

            $partly = false;

            foreach ($cities[$region->id] ?? [] as $city) {
                if (isset($priced[$city->id]) || self::anyPriced($districts[$city->id] ?? [], $priced)) {
                    $partly = true;
                    break;
                }
            }

            if (! $partly) {
                $rows[] = $region;

                continue;
            }

            foreach ($cities[$region->id] ?? [] as $city) {
                if (isset($priced[$city->id])) {
                    continue;
                }

                $own = $districts[$city->id] ?? [];

                if (! self::anyPriced($own, $priced)) {
                    $rows[] = $city;

                    continue;
                }

                foreach ($own as $district) {
                    if (! isset($priced[$district->id])) {
                        $rows[] = $district;
                    }
                }
            }
        }

        return $rows;
    }

    /** How many rows the store's list has, over every country it ships to. */
    public function count(StoreId $store): int
    {
        $count = 0;

        foreach ($this->platform->storeCountries($store) as $countryCode) {
            $count += count($this->in($store->value, $countryCode));
        }

        return $count;
    }

    /**
     * @param  list<PlaceDto>  $places
     * @param  array<string, int>  $priced
     */
    private static function anyPriced(array $places, array $priced): bool
    {
        foreach ($places as $place) {
            if (isset($priced[$place->id])) {
                return true;
            }
        }

        return false;
    }
}
