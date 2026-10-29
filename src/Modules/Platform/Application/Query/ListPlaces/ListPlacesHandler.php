<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Query\ListPlaces;

use Modules\Platform\Application\Places\PlaceLocks;
use Modules\Platform\Application\Query\ListAddressCountries\ListAddressCountriesHandler;
use Modules\Platform\Application\Query\NameOrder;
use Modules\Platform\Application\Query\PlaceReader;
use Modules\Platform\Application\Query\PlacesPanelReader;
use Modules\Platform\Domain\Exception\AddressCountryNotFound;
use Modules\Platform\Domain\Exception\PlaceNotFound;
use Modules\Platform\Public\Dto\PlaceDto;
use Modules\Platform\Public\Dto\TranslatedTextDto;
use Modules\Platform\Public\Enums\PlaceKind;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\Authorizer;
use Shared\Application\PermissionScope;

/**
 * One page of a country's places (platform.md §4.3 B2, B3): a page per level, with a trail (owner,
 * 2026-10-29, the screens' Q4). Super Admins only. An unknown country is AddressCountryNotFound; a
 * region that is not one of that country's, or a city that is not one of that region's, is
 * PlaceNotFound - the panel's not-found page (§4.3: "a city outside the region named, is not found").
 */
final readonly class ListPlacesHandler
{
    public const string PERMISSION = PlatformPermissions::PLACES_MANAGE;

    public function __construct(
        private Authorizer $authorizer,
        private PlacesPanelReader $panel,
        private PlaceReader $places,
        private ListAddressCountriesHandler $countries,
    ) {}

    public function handle(ListPlaces $query): PlacesLevel
    {
        $this->authorizer->authorize(self::PERMISSION, PermissionScope::global());
        $code = PlaceLocks::code($query->countryCode);
        $country = null;

        foreach ($this->countries->rows() as $row) {
            if ($row->code === $code) {
                $country = $row;

                break;
            }
        }

        if ($country === null) {
            throw new AddressCountryNotFound($code);
        }

        $parents = $this->trail($code, $query->regionId, $query->cityId);
        $parent = $parents === [] ? null : $parents[count($parents) - 1];
        // Under something retired - the country, the region or the city (PlaceDto's mark counts what
        // is above it): every row is out of the pickers whatever its own mark (§4.3, B2).
        $hidden = $country->retired || ($parent !== null && $parent->retired);
        $rows = [];

        foreach ($this->panel->level($code, $parent?->id) as $place) {
            $rows[] = new PlaceRow(
                $place['id'],
                PlaceKind::from($place['kind']),
                new TranslatedTextDto($place['ar'], $place['en']),
                $place['retired'],
                $hidden,
                $place['cities'],
                $place['districts'],
            );
        }

        return new PlacesLevel(
            $country,
            $parents,
            NameOrder::sort($rows, $query->locale, static fn (PlaceRow $row): string => $row->name->in($query->locale)),
        );
    }

    /**
     * The places above the page - the region, then the city - each read as place() reads it,
     * retired marked; read in one call.
     *
     * @return list<PlaceDto>
     */
    private function trail(string $country, ?string $regionId, ?string $cityId): array
    {
        if ($regionId === null) {
            if ($cityId !== null) {
                throw new PlaceNotFound($cityId);
            }

            return [];
        }

        $region = strtolower(trim($regionId));
        $city = $cityId === null ? null : strtolower(trim($cityId));
        $found = $this->places->places(array_values(array_filter([$region, $city])));
        $regionPlace = $found[$region] ?? null;

        if ($regionPlace === null || $regionPlace->countryCode !== $country || $regionPlace->kind !== PlaceKind::Region) {
            throw new PlaceNotFound($regionId);
        }

        if ($city === null) {
            return [$regionPlace];
        }

        $cityPlace = $found[$city] ?? null;

        if ($cityPlace === null || $cityPlace->kind !== PlaceKind::City || $cityPlace->regionId !== $regionPlace->id) {
            throw new PlaceNotFound((string) $cityId);
        }

        return [$regionPlace, $cityPlace];
    }
}
