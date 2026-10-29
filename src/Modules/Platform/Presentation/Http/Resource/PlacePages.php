<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Resource;

use Illuminate\Contracts\Foundation\Application;
use Modules\Platform\Application\Query\ListAddressCountries\AddressCountryRow;
use Modules\Platform\Application\Query\ListAddressCountries\ListAddressCountries;
use Modules\Platform\Application\Query\ListAddressCountries\ListAddressCountriesHandler;
use Modules\Platform\Application\Query\ListAddressCountries\ShippingStore;
use Modules\Platform\Application\Query\ListPlaces\ListPlaces;
use Modules\Platform\Application\Query\ListPlaces\ListPlacesHandler;
use Modules\Platform\Application\Query\ListPlaces\PlaceRow as PlaceListRow;
use Modules\Platform\Domain\ValueObject\IsoCountries;
use Modules\Platform\Public\Dto\PlaceDto;
use Modules\Platform\Public\Dto\TranslatedTextDto;
use Modules\Platform\Public\Enums\PlaceKind;

/**
 * E8's reads (platform.md §4.3 B1-B3), in the shape its pages want: names in the page's language
 * with the other one beside them. It decides nothing - which rows there are, their marks and their
 * counts are the handlers'; an unknown country or a trail that does not hold together is theirs to
 * refuse, and the panel's not-found page answers it.
 */
final readonly class PlacePages
{
    public function __construct(
        private Application $app,
    ) {}

    /**
     * The countries page, with Add Country's choices: every ISO country not on the list yet, each
     * found by its name in either language (Q10).
     */
    public function countries(ListAddressCountriesHandler $handler): PlacesPage
    {
        $rows = $handler->handle(new ListAddressCountries($this->locale()));
        $listed = array_map(static fn (AddressCountryRow $row): string => $row->code, $rows);

        $other = $this->locale() === 'en' ? 'ar' : 'en';
        $addable = [];

        foreach (StoreCountries::in($this->locale()) as $option) {
            if (! in_array($option->code, $listed, true)) {
                $addable[] = new AddableCountry($option->code, $option->name, IsoCountries::name($option->code, $other));
            }
        }

        return new PlacesPage(array_map($this->country(...), $rows), $addable);
    }

    /** A country's regions, a region's cities (with $regionId), or a city's districts (with both). */
    public function level(ListPlacesHandler $handler, string $countryCode, ?string $regionId, ?string $cityId): PlaceLevelPage
    {
        $level = $handler->handle(new ListPlaces($countryCode, $regionId, $cityId, $this->locale()));
        [$region, $city] = [$level->parents[0] ?? null, $level->parents[1] ?? null];

        return new PlaceLevelPage(
            $this->country($level->country),
            array_map(fn (PlaceDto $place): PlaceCrumb => new PlaceCrumb($place->id, $place->kind->value, $place->name->in($this->locale()), $place->retired), $level->parents),
            ($regionId === null ? PlaceKind::Region : ($cityId === null ? PlaceKind::City : PlaceKind::District))->value,
            array_map($this->place(...), $level->places),
            // The highest retired one names the note: a place's mark counts what is above it.
            match (true) {
                $level->country->retired => 'COUNTRY',
                $region?->retired === true => 'REGION',
                $city?->retired === true => 'CITY',
                default => null,
            },
        );
    }

    /** Also the stores page's: Add Country there reads the same rows (B4). */
    public function country(AddressCountryRow $row): PlaceCountryRow
    {
        return new PlaceCountryRow(
            $row->code,
            $row->name->in($this->locale()),
            $this->other($row->name),
            $row->retired,
            $row->hasPlaces,
            self::store($row->store, $this->locale()),
            $row->regions,
            $row->cities,
        );
    }

    public static function store(?ShippingStore $store, string $locale): ?PlaceStoreRow
    {
        return $store === null ? null : new PlaceStoreRow($store->code, $store->name->in($locale), $store->active);
    }

    private function place(PlaceListRow $row): PlaceRow
    {
        return new PlaceRow(
            $row->id,
            $row->kind->value,
            $row->name->in($this->locale()),
            $this->other($row->name),
            $row->name->ar,
            $row->name->en,
            $row->retired,
            $row->cities,
            $row->districts,
        );
    }

    private function other(TranslatedTextDto $name): string
    {
        return $name->in($this->locale() === 'en' ? 'ar' : 'en');
    }

    private function locale(): string
    {
        return $this->app->getLocale() === 'en' ? 'en' : 'ar';
    }
}
