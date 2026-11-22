<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/*
| The address countries and their place lists at the start (owner, 2026-10-29; Persistence/Places/README.md).
|
| - The list begins with five countries, not retired and shipped to by no store unless one already is:
|   Saudi Arabia, Egypt, the UAE, Qatar and Bahrain. Super Admins add, retire and restore from then on.
| - Each country's places load only when it has none yet, so nothing a Super Admin wrote is touched.
*/

return new class extends Migration
{
    private const array COUNTRIES = ['SA', 'EG', 'AE', 'QA', 'BH'];

    private const int CHUNK = 1000;

    public function up(): void
    {
        // Tests start from the world before this load - only the stores' own countries, no lists -
        // so a typed address stays typed there; PlaceListsLoadTest runs the load itself.
        if (app()->runningUnitTests()) {
            return;
        }

        $this->load();
    }

    public function load(): void
    {
        $now = now();

        DB::table('platform.address_countries')->insertOrIgnore(array_map(
            static fn (string $code): array => ['country_code' => $code, 'retired' => false, 'created_at' => $now, 'updated_at' => $now],
            self::COUNTRIES,
        ));

        foreach (self::COUNTRIES as $code) {
            if (DB::table('platform.places')->where('country_code', $code)->exists()) {
                continue;
            }

            $this->loadCountry($code, $now);
        }
    }

    public function down(): void
    {
        // Nothing: addresses point at the places, and the countries may be in use by then.
    }

    private function loadCountry(string $code, mixed $now): void
    {
        /** @var array{country: string, regions: list<array{ar: string, en: string, cities: list<array{ar: string, en: string, districts: list<array{ar: string, en: string}>}>}>} $list */
        $list = json_decode((string) file_get_contents(__DIR__."/../Places/places-{$code}.json"), true, 512, JSON_THROW_ON_ERROR);

        $rows = [];

        foreach ($list['regions'] as $region) {
            $rows[] = $regionRow = $this->row($code, 'REGION', null, $region, $now);

            foreach ($region['cities'] as $city) {
                $rows[] = $cityRow = $this->row($code, 'CITY', $regionRow['id'], $city, $now);

                foreach ($city['districts'] as $district) {
                    $rows[] = $this->row($code, 'DISTRICT', $cityRow['id'], $district, $now);
                }
            }
        }

        // Parents come before their children, so each chunk's keys point at rows already in.
        foreach (array_chunk($rows, self::CHUNK) as $chunk) {
            DB::table('platform.places')->insert($chunk);
        }
    }

    /**
     * @param  array{ar: string, en: string}  $place
     * @return array{id: string, country_code: string, kind: string, parent_id: ?string, name: string, retired: bool, created_at: mixed, updated_at: mixed}
     */
    private function row(string $code, string $kind, ?string $parentId, array $place, mixed $now): array
    {
        return [
            'id' => strtolower((string) Str::ulid()),
            'country_code' => $code,
            'kind' => $kind,
            'parent_id' => $parentId,
            'name' => json_encode(['ar' => $place['ar'], 'en' => $place['en']], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'retired' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }
};
