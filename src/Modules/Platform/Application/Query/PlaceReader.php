<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Query;

use Modules\Platform\Public\Dto\AddressCountryDto;
use Modules\Platform\Public\Dto\PlaceDto;

/**
 * The places and the ship-to countries as `PlatformApi` reads them (platform.md, stage 7). The
 * lists are a picker's: nothing retired, nor anything under something retired. By id, retired
 * places are read too, marked so.
 */
interface PlaceReader
{
    /**
     * @return list<AddressCountryDto>
     */
    public function addressCountries(): array;

    /**
     * @return list<PlaceDto>
     */
    public function regions(string $countryCode): array;

    /**
     * @return list<PlaceDto>
     */
    public function cities(string $regionId): array;

    /**
     * @return list<PlaceDto>
     */
    public function districts(string $cityId): array;

    /**
     * Every city of the country, whatever its region: the country-wide "Select all" (shipping.md §2.2).
     *
     * @return list<PlaceDto>
     */
    public function citiesOf(string $countryCode): array;

    /**
     * Every place of the country in use, of every kind, in one read: Shipping's uncovered places and
     * its country-wide "Select all" (shipping.md §1.4, §1.6).
     *
     * @return list<PlaceDto>
     */
    public function placesOf(string $countryCode): array;

    /**
     * @param  list<string>  $ids
     * @return array<string, PlaceDto> by id; an unknown id is left out
     */
    public function places(array $ids): array;

    /**
     * @return list<string> the store's ship-to countries, its own among them
     */
    public function storeCountries(string $storeId): array;

    /**
     * The id of the store that ships to the country, off stores included.
     */
    public function countryStore(string $countryCode): ?string;
}
