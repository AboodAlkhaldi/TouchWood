<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Query\ListPlaces;

use Modules\Platform\Public\Dto\TranslatedTextDto;
use Modules\Platform\Public\Enums\PlaceKind;

/**
 * One row of a places page (platform.md §4.3 B2, B3). `retired` is its own mark; `hidden`, that
 * something above it - the country, the region or the city - is retired, so it is in no picker
 * whatever its own mark, and back with what it was once that is restored. `cities` and
 * `districts` are what is in use under it - what Retire's dialog says leaves the pickers with it: a
 * region's cities, and its districts under its cities in use; a city's districts; nothing under a
 * district.
 */
final readonly class PlaceRow
{
    public function __construct(
        public string $id,
        public PlaceKind $kind,
        public TranslatedTextDto $name,
        public bool $retired,
        public bool $hidden,
        public int $cities,
        public int $districts,
    ) {}
}
