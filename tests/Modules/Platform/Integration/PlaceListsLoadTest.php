<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
| The address countries and place lists loaded at the start (owner, 2026-10-29; Persistence/Places/README.md).
| The migration skips the load while tests run, so each test here runs it itself.
*/

function placeListsLoad(): void
{
    $migration = require base_path('src/Modules/Platform/Infrastructure/Persistence/Migrations/2026_10_29_200000_load_address_countries_and_places.php');
    $migration->load();
}

/**
 * @return array<string, int>
 */
function placeListsCounts(): array
{
    $counts = [];

    foreach (DB::select('SELECT country_code, kind, count(*) AS n FROM platform.places GROUP BY 1, 2') as $row) {
        $counts["{$row->country_code} {$row->kind}"] = (int) $row->n;
    }

    ksort($counts);

    return $counts;
}

describe('the lists loaded at the start', function () {
    it('puts the five countries on the address list, in use, and ships to none of them it did not already', function () {
        $shipped = DB::table('platform.store_countries')->count();

        placeListsLoad();

        expect(DB::table('platform.address_countries')->whereIn('country_code', ['SA', 'EG', 'AE', 'QA', 'BH'])->where('retired', false)->count())->toBe(5)
            ->and(DB::table('platform.address_countries')->where('country_code', 'OM')->exists())->toBeFalse()
            ->and(DB::table('platform.store_countries')->count())->toBe($shipped);
    });

    it('reads each country from its own file', function () {
        foreach (['SA', 'EG', 'AE', 'QA', 'BH'] as $code) {
            $list = json_decode((string) file_get_contents(base_path("src/Modules/Platform/Infrastructure/Persistence/Places/places-{$code}.json")), true, 512, JSON_THROW_ON_ERROR);

            expect($list['country'] ?? null)->toBe($code);
        }
    });

    it('loads every region, city and district of each list', function () {
        placeListsLoad();

        expect(placeListsCounts())->toBe([
            'AE REGION' => 7,
            'BH REGION' => 4,
            'EG CITY' => 389,
            'EG REGION' => 27,
            'QA REGION' => 8,
            'SA CITY' => 4354,
            'SA DISTRICT' => 3721,
            'SA REGION' => 13,
        ]);
    });

    it('hangs each place under a parent of its own country and the kind above it', function () {
        placeListsLoad();

        $wrong = DB::selectOne(<<<'SQL'
            SELECT count(*) AS n FROM platform.places child
            JOIN platform.places parent ON parent.id = child.parent_id
            WHERE child.country_code <> parent.country_code
               OR (child.kind = 'CITY' AND parent.kind <> 'REGION')
               OR (child.kind = 'DISTRICT' AND parent.kind <> 'CITY')
            SQL);

        expect((int) $wrong->n)->toBe(0);
    });

    it('names a Saudi region as a region in English, never as its city', function () {
        placeListsLoad();

        $names = DB::table('platform.places')->where('country_code', 'SA')->where('kind', 'REGION')->pluck('name')
            ->map(static fn (string $name): string => json_decode($name, true)['en'])->all();

        expect($names)->toContain('Makkah Region', 'Riyadh Region', 'Eastern Province')
            ->and(array_filter($names, static fn (string $name): bool => ! str_ends_with($name, 'Region') && ! str_ends_with($name, 'Province')))->toBe([]);
    });

    it('changes nothing when it runs again', function () {
        placeListsLoad();
        $before = placeListsCounts();

        placeListsLoad();

        expect(placeListsCounts())->toBe($before);
    });

    it('leaves alone a country that already has places, and a country retired since', function () {
        DB::table('platform.address_countries')->insert(['country_code' => 'BH', 'retired' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('platform.places')->insert([
            'id' => strtolower((string) Str::ulid()), 'country_code' => 'BH', 'kind' => 'REGION', 'parent_id' => null,
            'name' => json_encode(['ar' => 'المنامة', 'en' => 'Manama']), 'retired' => false, 'created_at' => now(), 'updated_at' => now(),
        ]);

        placeListsLoad();

        expect(placeListsCounts()['BH REGION'])->toBe(1)
            ->and(DB::table('platform.address_countries')->where('country_code', 'BH')->value('retired'))->toBeTrue();
    });
});
