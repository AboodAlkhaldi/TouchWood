<?php

declare(strict_types=1);

namespace Modules\Platform\Infrastructure\Eloquent;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;
use Modules\Platform\Application\Query\PlaceReader;
use Modules\Platform\Domain\ValueObject\IsoCountries;
use Modules\Platform\Public\Dto\AddressCountryDto;
use Modules\Platform\Public\Dto\PlaceDto;
use Modules\Platform\Public\Dto\TranslatedTextDto;
use Modules\Platform\Public\Enums\PlaceKind;
use stdClass;

/**
 * Reads the places with what sits above them, so a place is retired for the readers when it, its
 * city, its region or its country is (stage 7 step 1, Q4): retiring a region hides its cities and
 * their districts without touching their own marks, and restoring it brings them back.
 */
final readonly class DatabasePlaceReader implements PlaceReader
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function addressCountries(): array
    {
        $rows = $this->db->table('platform.address_countries as country')
            ->where('country.retired', false)
            ->orderBy('country.country_code')
            ->selectRaw(<<<'SQL'
                country.country_code,
                EXISTS (
                    SELECT 1 FROM platform.places region
                    WHERE region.country_code = country.country_code AND region.kind = 'REGION' AND NOT region.retired
                ) AS has_places
                SQL)
            ->get();

        $countries = [];

        foreach ($rows as $row) {
            $code = (string) $row->country_code;
            $countries[] = new AddressCountryDto(
                $code,
                new TranslatedTextDto(IsoCountries::name($code, 'ar'), IsoCountries::name($code, 'en')),
                (bool) $row->has_places,
            );
        }

        return $countries;
    }

    public function regions(string $countryCode): array
    {
        return $this->live($this->query()->where('place.country_code', $countryCode)->where('place.kind', PlaceKind::Region->value));
    }

    public function cities(string $regionId): array
    {
        return Str::isUlid($regionId)
            ? $this->live($this->query()->where('place.parent_id', strtolower($regionId))->where('place.kind', PlaceKind::City->value))
            : [];
    }

    public function districts(string $cityId): array
    {
        return Str::isUlid($cityId)
            ? $this->live($this->query()->where('place.parent_id', strtolower($cityId))->where('place.kind', PlaceKind::District->value))
            : [];
    }

    public function citiesOf(string $countryCode): array
    {
        return $this->live($this->query()->where('place.country_code', $countryCode)->where('place.kind', PlaceKind::City->value));
    }

    public function placesOf(string $countryCode): array
    {
        return $this->live($this->query()->where('place.country_code', $countryCode));
    }

    public function places(array $ids): array
    {
        $ids = array_values(array_unique(array_map(strtolower(...), array_filter($ids, Str::isUlid(...)))));

        if ($ids === []) {
            return [];
        }

        $places = [];

        foreach ($this->query()->whereIn('place.id', $ids)->get() as $row) {
            $place = $this->dto($row);
            $places[$place->id] = $place;
        }

        return $places;
    }

    public function storeCountries(string $storeId): array
    {
        $codes = [];

        foreach ($this->db->table('platform.store_countries')->where('store_id', strtolower($storeId))->orderBy('country_code')->pluck('country_code') as $code) {
            $codes[] = (string) $code;
        }

        return $codes;
    }

    public function countryStore(string $countryCode): ?string
    {
        $storeId = $this->db->table('platform.store_countries')->where('country_code', $countryCode)->value('store_id');

        return $storeId === null ? null : (string) $storeId;
    }

    private function query(): Builder
    {
        return $this->db->table('platform.places as place')
            ->join('platform.address_countries as country', 'country.country_code', '=', 'place.country_code')
            ->leftJoin('platform.places as parent', 'parent.id', '=', 'place.parent_id')
            ->leftJoin('platform.places as grandparent', 'grandparent.id', '=', 'parent.parent_id')
            ->selectRaw(<<<'SQL'
                place.id, place.country_code, place.kind, place.name,
                CASE place.kind WHEN 'CITY' THEN place.parent_id WHEN 'DISTRICT' THEN parent.parent_id END AS region_id,
                CASE place.kind WHEN 'DISTRICT' THEN place.parent_id END AS city_id,
                (place.retired OR COALESCE(parent.retired, false) OR COALESCE(grandparent.retired, false) OR country.retired) AS hidden
                SQL)
            ->orderBy('place.id');
    }

    /**
     * @return list<PlaceDto>
     */
    private function live(Builder $query): array
    {
        $places = [];

        foreach ($query->get() as $row) {
            $place = $this->dto($row);

            if (! $place->retired) {
                $places[] = $place;
            }
        }

        return $places;
    }

    private function dto(stdClass $row): PlaceDto
    {
        /** @var array{ar: string, en: string} $name */
        $name = json_decode((string) $row->name, true, flags: JSON_THROW_ON_ERROR);

        return new PlaceDto(
            (string) $row->id,
            (string) $row->country_code,
            PlaceKind::from((string) $row->kind),
            $row->region_id === null ? null : (string) $row->region_id,
            $row->city_id === null ? null : (string) $row->city_id,
            new TranslatedTextDto($name['ar'], $name['en']),
            (bool) $row->hidden,
        );
    }
}
