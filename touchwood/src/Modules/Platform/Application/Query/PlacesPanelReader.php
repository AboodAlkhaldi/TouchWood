<?php

declare(strict_types=1);

namespace Modules\Platform\Application\Query;

/**
 * What the Super Admins' places pages read (platform.md §4.3 B1–B4): unlike PlaceReader's picker
 * lists, retired rows are included, each with its own mark, and the counts say what is in use under
 * each row - what Retire's dialog names (B3).
 *
 * "In use" counts a place whose own mark is not retired and whose ancestors under the row are not
 * either: a region's cities in use, a region's districts in use under its cities in use, a city's
 * districts in use.
 */
interface PlacesPanelReader
{
    /**
     * Every address country, retired ones included, by code.
     *
     * @return list<array{code: string, retired: bool, regions: int, cities: int}>
     */
    public function countries(): array;

    /**
     * One level of a country's places, retired ones included: its regions when there is no parent,
     * otherwise the cities of a region or the districts of a city.
     *
     * @return list<array{id: string, kind: string, ar: string, en: string, retired: bool, cities: int, districts: int}>
     */
    public function level(string $countryCode, ?string $parentId): array;

    /**
     * Every store's ship-to countries, by store id.
     *
     * @return array<string, list<string>>
     */
    public function shipsTo(): array;
}
