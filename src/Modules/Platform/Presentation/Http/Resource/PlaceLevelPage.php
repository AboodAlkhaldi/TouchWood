<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Resource;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A level's page of E8 (platform.md §4.3; owner, 2026-10-29, Q4): a country's regions, a region's
 * cities or a city's districts, with the trail above it.
 */
#[TypeScript]
final class PlaceLevelPage extends Data
{
    /**
     * @param  list<PlaceCrumb>  $parents  the region, then the city, above this level
     * @param  string  $kind  what this page lists: REGION, CITY or DISTRICT
     * @param  list<PlaceRow>  $places  sorted by name in the page's language, retired ones too
     * @param  string|null  $hiddenBy  COUNTRY, REGION or CITY when that, above this level, is retired: nothing is added here
     */
    public function __construct(
        public PlaceCountryRow $country,
        public array $parents,
        public string $kind,
        public array $places,
        public ?string $hiddenBy,
    ) {}
}
