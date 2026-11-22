<?php

declare(strict_types=1);

namespace Modules\Access\Application\Address;

use Modules\Access\Domain\Exception\AddressCountryServedByAnotherStore;
use Modules\Access\Domain\Exception\InvalidAddress;
use Modules\Access\Domain\Model\AddressPlaces;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\PlaceDto;
use Modules\Platform\Public\Enums\PlaceKind;

/**
 * An address's country and places checked against Platform's lists (amendment 76; shipping.md §1.2,
 * §9.3 #1, #4, #10):
 *
 * - the country is one of Platform's address countries, and not one another of our stores ships to
 *   (an off store's countries still count): that store is named instead;
 * - where Platform has a place list for the country, the region is picked; the city is picked in
 *   that region or typed as "Other"; the district is picked in that city or typed; with no list,
 *   nothing is picked;
 * - a place retired since stays on the address while the customer leaves it as it was (owner,
 *   2026-10-28, step 2 Q2); a new choice must be a place in use;
 * - the names of the places picked fill the format's `administrative_area`, `city` and `district`,
 *   in the page's language, so the store's format checks them as any typed value.
 */
final readonly class AddressPlaceRules
{
    public function __construct(private PlatformApi $platform) {}

    /**
     * @param  array<string, string>  $fields  the values typed
     * @param  AddressPlaces|null  $before  the address as it was, when it is being changed
     * @return array<string, string> the values with the picked places' names filled in
     *
     * @throws AddressCountryServedByAnotherStore|InvalidAddress
     */
    public function apply(string $storeId, AddressPlaces $places, array $fields, ?AddressPlaces $before): array
    {
        $code = $places->countryCode;
        $country = null;

        foreach ($this->platform->addressCountries($places->language) as $offered) {
            if ($offered->code === $code) {
                $country = $offered;
            }
        }

        if ($country === null && $before?->countryCode !== $code) {
            throw new InvalidAddress('country', 'not one of the countries offered');
        }

        $servedBy = $this->platform->countryStore($code);

        if ($servedBy !== null && $servedBy->id !== strtolower($storeId)) {
            throw new AddressCountryServedByAnotherStore($code, $servedBy->code, $servedBy->name->ar, $servedBy->name->en);
        }

        $kept = $before !== null && $before->countryCode === $code;

        if ($country === null || ! $country->hasPlaces) {
            // No list (or the country retired and kept as it was): everything typed, unless the
            // places are exactly the ones the address already had.
            if ($places->ids() !== [] && ! ($kept && $places->samePlacesAs($before))) {
                throw new InvalidAddress('places', 'typed: this country has no place list');
            }

            return $this->named($places, $fields);
        }

        if ($places->regionId === null) {
            throw new InvalidAddress('administrative_area', 'picked from the list');
        }

        $found = $this->platform->place($places->ids());
        $region = $this->picked($found, $places->regionId, PlaceKind::Region, $code, null, $kept ? $before->regionId : null);
        $fields['administrative_area'] = $region->name->in($places->language);

        if ($places->cityId !== null) {
            $city = $this->picked($found, $places->cityId, PlaceKind::City, $code, $region->id, $kept ? $before->cityId : null);
            $fields['city'] = $city->name->in($places->language);
        }

        if ($places->districtId !== null) {
            $district = $this->picked($found, $places->districtId, PlaceKind::District, $code, (string) $places->cityId, $kept ? $before->districtId : null);
            $fields['district'] = $district->name->in($places->language);
        }

        return $fields;
    }

    /**
     * The names of places kept as they were, so the text never says one place while the id names
     * another.
     *
     * @param  array<string, string>  $fields
     * @return array<string, string>
     */
    private function named(AddressPlaces $places, array $fields): array
    {
        $found = $this->platform->place($places->ids());
        $keys = ['administrative_area' => $places->regionId, 'city' => $places->cityId, 'district' => $places->districtId];

        foreach ($keys as $field => $id) {
            if ($id !== null && isset($found[$id])) {
                $fields[$field] = $found[$id]->name->in($places->language);
            }
        }

        return $fields;
    }

    /**
     * @param  array<string, PlaceDto>  $found
     * @param  string|null  $above  the region a city sits in, the city a district sits in
     * @param  string|null  $kept  the same place the address had before, which may be retired since
     *
     * @throws InvalidAddress
     */
    private function picked(array $found, string $id, PlaceKind $kind, string $country, ?string $above, ?string $kept): PlaceDto
    {
        $place = $found[$id] ?? null;
        $field = match ($kind) {
            PlaceKind::Region => 'administrative_area',
            PlaceKind::City => 'city',
            PlaceKind::District => 'district',
        };

        $parent = match ($kind) {
            PlaceKind::Region => null,
            PlaceKind::City => $place?->regionId,
            PlaceKind::District => $place?->cityId,
        };

        if ($place === null || $place->kind !== $kind || $place->countryCode !== $country || $parent !== $above) {
            throw new InvalidAddress($field, 'picked from the list');
        }

        if ($place->retired && $kept !== $id) {
            throw new InvalidAddress($field, 'a place still in use');
        }

        return $place;
    }
}
