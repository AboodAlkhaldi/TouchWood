<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Query\ListPlaces;

/**
 * One page of a country's places (platform.md §4.3 B2), named as its address names it: a country's
 * regions with neither id, a region's cities with $regionId, a city's districts with both - retired
 * ones included, sorted by name in the page's language.
 */
final readonly class ListPlaces
{
    public function __construct(
        public string $countryCode,
        public ?string $regionId,
        public ?string $cityId,
        public string $locale,
    ) {}
}
