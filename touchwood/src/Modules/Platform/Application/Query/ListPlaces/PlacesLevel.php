<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Query\ListPlaces;

use Modules\Platform\Application\Query\ListAddressCountries\AddressCountryRow;
use Modules\Platform\Public\Dto\PlaceDto;

/**
 * A places page (platform.md §4.3 B2): the country, the places above this level for the trail - the
 * region, then the city, each marked retired as PlatformApi::place() marks it - and the level's rows.
 */
final readonly class PlacesLevel
{
    /**
     * @param  list<PlaceDto>  $parents
     * @param  list<PlaceRow>  $places
     */
    public function __construct(
        public AddressCountryRow $country,
        public array $parents,
        public array $places,
    ) {}
}
