<?php

declare(strict_types=1);

namespace Modules\Shipping\Application\Query;

use Modules\Platform\Public\Dto\PlaceDto;
use Modules\Platform\Public\Dto\TranslatedTextDto;
use Modules\Platform\Public\Enums\PlaceKind;

/**
 * A place as the shipping screens show it (shipping.md §4.1): its kind and name, with its region's and
 * city's names, so a district reads with its city and region; `retired` when it has left the
 * pickers yet its price still applies (§9.3 #4).
 */
final readonly class PlaceRow
{
    public function __construct(
        public string $id,
        public PlaceKind $kind,
        public TranslatedTextDto $name,
        public ?TranslatedTextDto $regionName,
        public ?TranslatedTextDto $cityName,
        public bool $retired,
    ) {}

    /**
     * @param  array<string, PlaceDto>  $parents  the places above, by id
     */
    public static function of(PlaceDto $place, array $parents): self
    {
        return new self(
            $place->id,
            $place->kind,
            $place->name,
            $place->regionId === null ? null : ($parents[$place->regionId] ?? null)?->name,
            $place->cityId === null ? null : ($parents[$place->cityId] ?? null)?->name,
            $place->retired,
        );
    }

    /**
     * The ids of the places above these, to read in one go.
     *
     * @param  list<PlaceDto>  $places
     * @return list<string>
     */
    public static function parentIds(array $places): array
    {
        $ids = [];

        foreach ($places as $place) {
            foreach ([$place->regionId, $place->cityId] as $id) {
                if ($id !== null) {
                    $ids[$id] = true;
                }
            }
        }

        return array_keys($ids);
    }
}
