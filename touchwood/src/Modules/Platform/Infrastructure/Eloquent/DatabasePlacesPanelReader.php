<?php

declare(strict_types=1);

namespace Modules\Platform\Infrastructure\Eloquent;

use Illuminate\Database\ConnectionInterface;
use Modules\Platform\Application\Query\PlacesPanelReader;

/**
 * One query per read, the counts worked out by the database (platform.md §4.3 B1–B4).
 */
final readonly class DatabasePlacesPanelReader implements PlacesPanelReader
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function countries(): array
    {
        $rows = $this->db->select(<<<'SQL'
            SELECT country.country_code, country.retired,
                (SELECT count(*) FROM platform.places region
                    WHERE region.country_code = country.country_code AND region.kind = 'REGION' AND NOT region.retired) AS regions,
                (SELECT count(*) FROM platform.places city
                    JOIN platform.places region ON region.id = city.parent_id
                    WHERE city.country_code = country.country_code AND city.kind = 'CITY'
                        AND NOT city.retired AND NOT region.retired) AS cities
            FROM platform.address_countries country
            ORDER BY country.country_code
            SQL);

        $countries = [];

        foreach ($rows as $row) {
            $countries[] = [
                'code' => (string) $row->country_code,
                'retired' => (bool) $row->retired,
                'regions' => (int) $row->regions,
                'cities' => (int) $row->cities,
            ];
        }

        return $countries;
    }

    public function level(string $countryCode, ?string $parentId): array
    {
        $rows = $this->db->select(<<<'SQL'
            SELECT place.id, place.kind, place.name, place.retired,
                CASE place.kind WHEN 'REGION' THEN
                    (SELECT count(*) FROM platform.places city WHERE city.parent_id = place.id AND NOT city.retired)
                ELSE 0 END AS cities,
                CASE place.kind
                    WHEN 'REGION' THEN
                        (SELECT count(*) FROM platform.places district
                            JOIN platform.places city ON city.id = district.parent_id
                            WHERE city.parent_id = place.id AND NOT city.retired AND NOT district.retired)
                    WHEN 'CITY' THEN
                        (SELECT count(*) FROM platform.places district WHERE district.parent_id = place.id AND NOT district.retired)
                    ELSE 0
                END AS districts
            FROM platform.places place
            WHERE place.country_code = ? AND
            SQL
            // Two statements, not one with a CASE: each then uses parent_id's index.
            .($parentId === null ? ' place.parent_id IS NULL' : ' place.parent_id = ?')
            .' ORDER BY place.id', $parentId === null ? [$countryCode] : [$countryCode, $parentId]);

        $places = [];

        foreach ($rows as $row) {
            /** @var array{ar: string, en: string} $name */
            $name = json_decode((string) $row->name, true, flags: JSON_THROW_ON_ERROR);
            $places[] = [
                'id' => (string) $row->id,
                'kind' => (string) $row->kind,
                'ar' => $name['ar'],
                'en' => $name['en'],
                'retired' => (bool) $row->retired,
                'cities' => (int) $row->cities,
                'districts' => (int) $row->districts,
            ];
        }

        return $places;
    }

    public function shipsTo(): array
    {
        $byStore = [];

        foreach ($this->db->table('platform.store_countries')->orderBy('country_code')->get(['store_id', 'country_code']) as $row) {
            $byStore[(string) $row->store_id][] = (string) $row->country_code;
        }

        return $byStore;
    }
}
