<?php

declare(strict_types=1);

namespace Modules\Shipping\Application\Query\ListUncoveredPlaces;

use Modules\Platform\Public\Dto\TranslatedTextDto;
use Modules\Shipping\Application\Query\PlaceRow;

/**
 * One country the store ships to and its places no switched-on carrier serves: the biggest place each
 * time - a whole region, else a city, else a district (gap 4.3) - sorted by name in the page's language.
 */
final readonly class UncoveredCountry
{
    /**
     * @param  list<PlaceRow>  $places
     */
    public function __construct(
        public string $code,
        public TranslatedTextDto $name,
        public array $places,
    ) {}
}
