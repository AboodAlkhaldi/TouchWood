<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Platform\Application\Command\AddAddressCountry\AddAddressCountry;
use Modules\Platform\Application\Command\AddAddressCountry\AddAddressCountryHandler;
use Modules\Platform\Application\Command\AddPlace\AddPlace;
use Modules\Platform\Application\Command\AddPlace\AddPlaceHandler;
use Modules\Platform\Application\Command\AddStoreCountry\AddStoreCountry;
use Modules\Platform\Application\Command\AddStoreCountry\AddStoreCountryHandler;
use Modules\Platform\Application\Command\RetireAddressCountry\RetireAddressCountry;
use Modules\Platform\Application\Command\RetireAddressCountry\RetireAddressCountryHandler;
use Modules\Platform\Application\Command\RetirePlace\RetirePlace;
use Modules\Platform\Application\Command\RetirePlace\RetirePlaceHandler;
use Modules\Platform\Application\Query\ListAddressCountries\AddressCountryRow;
use Modules\Platform\Application\Query\ListAddressCountries\ListAddressCountries;
use Modules\Platform\Application\Query\ListAddressCountries\ListAddressCountriesHandler;
use Modules\Platform\Application\Query\ListPlaces\ListPlaces;
use Modules\Platform\Application\Query\ListPlaces\ListPlacesHandler;
use Modules\Platform\Application\Query\ListPlaces\PlaceRow;
use Modules\Platform\Application\Query\ListPlaces\PlacesLevel as ListPlacesLevel;
use Modules\Platform\Application\Query\ListStores\ListStores;
use Modules\Platform\Application\Query\ListStores\ListStoresHandler;
use Modules\Platform\Application\Query\ListStores\ShipsToCountry;
use Modules\Platform\Application\Query\ListStores\StoreSummary;
use Modules\Platform\Domain\Exception\AddressCountryNotFound;
use Modules\Platform\Domain\Exception\PlaceNotFound;
use Modules\Platform\Public\Dto\PlaceDto;
use Modules\Platform\Public\Enums\PlaceKind;
use Modules\Platform\Public\PlatformPermissions;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    Fx::actAsStaff(Fx::staff(superAdmin: true));
});

/*
| The places pages' reads (platform.md §4.3 B1–B4; built by the backend session, owner 2026-10-29,
| the screens' Q16): retired rows included, each with its own mark, and the counts of what is in use
| under each row - what Retire's dialog names.
|
| Every helper is named after this file's subject: a function in a Pest file is global to the suite.
*/

function placesPanelAdd(string $country, PlaceKind $kind, ?string $parent, string $ar, string $en): string
{
    return app(AddPlaceHandler::class)->handle(new AddPlace($country, $kind->value, $parent, $ar, $en));
}

/**
 * Riyadh Region (two cities, one with two districts, the other retired) and Eastern Province (empty).
 *
 * @return array{riyadh_region: string, eastern: string, riyadh: string, kharj: string, olaya: string, malaz: string}
 */
function placesPanelSaudi(): array
{
    $region = placesPanelAdd('SA', PlaceKind::Region, null, 'منطقة الرياض', 'Riyadh Region');
    $eastern = placesPanelAdd('SA', PlaceKind::Region, null, 'المنطقة الشرقية', 'Eastern Province');
    $riyadh = placesPanelAdd('SA', PlaceKind::City, $region, 'الرياض', 'Riyadh');
    $kharj = placesPanelAdd('SA', PlaceKind::City, $region, 'الخرج', 'Al Kharj');
    $olaya = placesPanelAdd('SA', PlaceKind::District, $riyadh, 'العليا', 'Al Olaya');
    $malaz = placesPanelAdd('SA', PlaceKind::District, $riyadh, 'الملز', 'Al Malaz');
    placesPanelAdd('SA', PlaceKind::District, $kharj, 'السيح', 'As Sayh');
    app(RetirePlaceHandler::class)->handle(new RetirePlace($kharj));
    app(RetirePlaceHandler::class)->handle(new RetirePlace($malaz));

    return ['riyadh_region' => $region, 'eastern' => $eastern, 'riyadh' => $riyadh, 'kharj' => $kharj, 'olaya' => $olaya, 'malaz' => $malaz];
}

/**
 * @return array<string, AddressCountryRow>
 */
function placesPanelCountries(string $locale = 'en'): array
{
    $rows = [];

    foreach (app(ListAddressCountriesHandler::class)->handle(new ListAddressCountries($locale)) as $row) {
        $rows[$row->code] = $row;
    }

    return $rows;
}

/**
 * @param  list<PlaceRow>  $rows
 * @return array<string, array{retired: bool, cities: int, districts: int}>
 */
function placesPanelRows(array $rows): array
{
    $out = [];

    foreach ($rows as $row) {
        $out[$row->name->en] = ['retired' => $row->retired, 'cities' => $row->cities, 'districts' => $row->districts];
    }

    return $out;
}

function placesPanelLevel(string $country, ?string $region = null, ?string $city = null, string $locale = 'en'): ListPlacesLevel
{
    return app(ListPlacesHandler::class)->handle(new ListPlaces($country, $region, $city, $locale));
}

/**
 * @return array<string, StoreSummary>
 */
function placesPanelStores(): array
{
    $stores = [];

    foreach (app(ListStoresHandler::class)->handle(new ListStores('en')) as $store) {
        $stores[$store->code] = $store;
    }

    return $stores;
}

/**
 * The statements one call sends, read after a first call has warmed the caches a request keeps.
 */
function placesPanelQueries(Closure $read): int
{
    $read();
    DB::flushQueryLog();
    DB::enableQueryLog();
    $read();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

describe('the address countries (B1)', function () {
    it('lists every country, retired ones too, with its store, its list and its places in use', function () {
        placesPanelSaudi();
        app(AddAddressCountryHandler::class)->handle(new AddAddressCountry('QA'));
        app(RetireAddressCountryHandler::class)->handle(new RetireAddressCountry('QA'));
        DB::table('platform.stores')->where('code', 'eg')->update(['is_active' => false]);

        $rows = placesPanelCountries();

        expect(array_keys($rows))->toEqualCanonicalizing(['SA', 'EG', 'AE', 'QA'])
            ->and($rows['SA']->hasPlaces)->toBeTrue()
            ->and([$rows['SA']->regions, $rows['SA']->cities])->toBe([2, 1])
            ->and($rows['SA']->store?->code)->toBe('sa')
            ->and($rows['EG']->store?->active)->toBeFalse()
            ->and($rows['EG']->hasPlaces)->toBeFalse()
            ->and($rows['QA']->retired)->toBeTrue()
            ->and($rows['QA']->store)->toBeNull()
            ->and($rows['SA']->name->en)->toBe('Saudi Arabia');
    });

    it('reads a region with no city as a place list, and a country whose regions are all retired as none', function () {
        $p = placesPanelSaudi();
        app(RetirePlaceHandler::class)->handle(new RetirePlace($p['riyadh_region']));

        $saudi = placesPanelCountries()['SA'];
        expect([$saudi->hasPlaces, $saudi->regions, $saudi->cities])->toBe([true, 1, 0]);

        app(RetirePlaceHandler::class)->handle(new RetirePlace($p['eastern']));

        $saudi = placesPanelCountries()['SA'];
        expect([$saudi->hasPlaces, $saudi->regions, $saudi->cities])->toBe([false, 0, 0]);
    });

    it('reads the countries in a fixed number of statements', function () {
        placesPanelSaudi();

        // The countries with their counts, and the ship-to lists (the stores are cached).
        expect(placesPanelQueries(fn () => placesPanelCountries()))->toBe(2);
    });

    it('sorts by name in the page\'s language', function () {
        $english = array_keys(placesPanelCountries('en'));
        $arabic = array_keys(placesPanelCountries('ar'));

        expect($english)->toBe(['EG', 'SA', 'AE'])
            ->and($arabic)->toBe(['AE', 'SA', 'EG']);
    });

    it('is a Super Admin\'s read only, as changing a store\'s countries is', function () {
        Fx::actAsAdmin(['sa'], [PlatformPermissions::STORE_UPDATE, PlatformPermissions::STORE_VIEW]);

        expect(fn () => placesPanelCountries())->toThrow(Unauthorized::class)
            ->and(app(ListStoresHandler::class)->mayChangeCountries())->toBeFalse();
    });
});

describe('a page of places (B2, B3)', function () {
    it('lists a country\'s regions, retired ones too, with what is in use under each', function () {
        $p = placesPanelSaudi();
        app(RetirePlaceHandler::class)->handle(new RetirePlace($p['eastern']));

        $level = placesPanelLevel('sa');

        expect($level->country->code)->toBe('SA')
            ->and($level->parents)->toBe([])
            ->and(placesPanelRows($level->places))->toBe([
                'Eastern Province' => ['retired' => true, 'cities' => 0, 'districts' => 0],
                'Riyadh Region' => ['retired' => false, 'cities' => 1, 'districts' => 1],
            ]);
    });

    it('lists a region\'s cities and a city\'s districts, each with its own mark, and the trail above', function () {
        $p = placesPanelSaudi();

        $cities = placesPanelLevel('SA', $p['riyadh_region']);
        $districts = placesPanelLevel('SA', strtoupper($p['riyadh_region']), strtoupper($p['riyadh']));

        expect(placesPanelRows($cities->places))->toBe([
            'Al Kharj' => ['retired' => true, 'cities' => 0, 'districts' => 1],
            'Riyadh' => ['retired' => false, 'cities' => 0, 'districts' => 1],
        ])
            ->and(array_map(static fn (PlaceDto $place): string => $place->name->en, $cities->parents))->toBe(['Riyadh Region'])
            ->and(placesPanelRows($districts->places))->toBe([
                'Al Malaz' => ['retired' => true, 'cities' => 0, 'districts' => 0],
                'Al Olaya' => ['retired' => false, 'cities' => 0, 'districts' => 0],
            ])
            ->and(array_map(static fn (PlaceDto $place): string => $place->name->en, $districts->parents))->toBe(['Riyadh Region', 'Riyadh']);
    });

    it('marks the trail retired as the pickers see it', function () {
        $p = placesPanelSaudi();

        $level = placesPanelLevel('SA', $p['riyadh_region'], $p['kharj']);

        expect(array_map(static fn (PlaceDto $place): bool => $place->retired, $level->parents))->toBe([false, true]);
    });

    it('marks every row hidden under a retired city, region or country, each keeping its own mark', function () {
        $p = placesPanelSaudi();

        $underKharj = placesPanelLevel('SA', $p['riyadh_region'], $p['kharj']);
        $underRiyadh = placesPanelLevel('SA', $p['riyadh_region'], $p['riyadh']);
        app(AddAddressCountryHandler::class)->handle(new AddAddressCountry('QA'));
        placesPanelAdd('QA', PlaceKind::Region, null, 'الدوحة', 'Doha Municipality');
        app(RetireAddressCountryHandler::class)->handle(new RetireAddressCountry('QA'));
        $regions = placesPanelLevel('QA');

        expect(array_map(static fn (PlaceRow $row): array => [$row->retired, $row->hidden], $underKharj->places))->toBe([[false, true]])
            ->and(array_map(static fn (PlaceRow $row): bool => $row->hidden, $underRiyadh->places))->toBe([false, false])
            ->and($regions->country->retired)->toBeTrue()
            ->and(array_map(static fn (PlaceRow $row): array => [$row->name->en, $row->retired, $row->hidden], $regions->places))
            ->toBe([['Doha Municipality', false, true]]);
    });

    it('sorts a level by name in the page\'s language', function () {
        $p = placesPanelSaudi();

        // العليا before الملز in Arabic; Al Malaz before Al Olaya in English.
        expect(array_map(static fn (PlaceRow $row): string => $row->name->en, placesPanelLevel('SA', $p['riyadh_region'], $p['riyadh'], 'ar')->places))
            ->toBe(['Al Olaya', 'Al Malaz'])
            ->and(array_map(static fn (PlaceRow $row): string => $row->name->en, placesPanelLevel('SA', $p['riyadh_region'], $p['riyadh'], 'en')->places))
            ->toBe(['Al Malaz', 'Al Olaya']);
    });

    it('reads a level in a fixed number of statements, however many rows it has', function () {
        $p = placesPanelSaudi();
        $few = placesPanelQueries(fn () => placesPanelLevel('SA', $p['riyadh_region'], $p['riyadh']));

        foreach (range(1, 8) as $n) {
            placesPanelAdd('SA', PlaceKind::District, $p['riyadh'], 'حي '.$n, 'District '.$n);
        }

        expect(placesPanelQueries(fn () => placesPanelLevel('SA', $p['riyadh_region'], $p['riyadh'])))->toBe($few)
            // The countries, the ship-to lists, the trail, the level.
            ->and($few)->toBe(4);
    });

    it('refuses a parent that is a district, of another country or unknown, and an unknown country', function (Closure $query, string $error) {
        $p = placesPanelSaudi();
        placesPanelAdd('EG', PlaceKind::Region, null, 'القاهرة', 'Cairo Governorate');

        expect(fn () => app(ListPlacesHandler::class)->handle($query($p)))->toThrow($error);
    })->with([
        'a city as the region' => [fn (array $p) => new ListPlaces('SA', $p['riyadh'], null, 'en'), PlaceNotFound::class],
        'a district as the city' => [fn (array $p) => new ListPlaces('SA', $p['riyadh_region'], $p['olaya'], 'en'), PlaceNotFound::class],
        'a city outside the region named' => [fn (array $p) => new ListPlaces('SA', $p['eastern'], $p['riyadh'], 'en'), PlaceNotFound::class],
        'a city with no region' => [fn (array $p) => new ListPlaces('SA', null, $p['riyadh'], 'en'), PlaceNotFound::class],
        'another country' => [fn (array $p) => new ListPlaces('EG', $p['riyadh_region'], null, 'en'), PlaceNotFound::class],
        'an unknown place' => [fn (array $p) => new ListPlaces('SA', '01jbz0000000000000000000aa', null, 'en'), PlaceNotFound::class],
        'not an id' => [fn (array $p) => new ListPlaces('SA', 'riyadh', null, 'en'), PlaceNotFound::class],
        'an unknown country' => [fn (array $p) => new ListPlaces('QA', null, null, 'en'), AddressCountryNotFound::class],
    ]);

    it('is a Super Admin\'s read only', function () {
        Fx::actAsAdmin(['sa'], [PlatformPermissions::STORE_UPDATE, PlatformPermissions::STORE_VIEW]);

        expect(fn () => placesPanelLevel('SA'))->toThrow(Unauthorized::class);
    });
});

describe('the stores page (B4)', function () {
    it('gives each store its ship-to countries, its own first', function () {
        app(AddAddressCountryHandler::class)->handle(new AddAddressCountry('BH'));
        app(AddAddressCountryHandler::class)->handle(new AddAddressCountry('QA'));
        placesPanelAdd('BH', PlaceKind::City, placesPanelAdd('BH', PlaceKind::Region, null, 'المنامة', 'Capital'), 'المنامة', 'Manama');
        placesPanelAdd('QA', PlaceKind::City, placesPanelAdd('QA', PlaceKind::Region, null, 'الدوحة', 'Doha Municipality'), 'الدوحة', 'Doha');
        app(AddStoreCountryHandler::class)->handle(new AddStoreCountry('sa', 'QA'));
        app(AddStoreCountryHandler::class)->handle(new AddStoreCountry('sa', 'BH'));

        $stores = placesPanelStores();
        $codes = static fn (StoreSummary $store): array => array_map(static fn (ShipsToCountry $country): string => $country->code, $store->shipsTo);

        expect(app(ListStoresHandler::class)->mayChangeCountries())->toBeTrue()
            ->and($codes($stores['sa']))->toBe(['SA', 'BH', 'QA'])
            ->and($stores['sa']->shipsTo[1]->name->en)->toBe('Bahrain')
            ->and($stores['sa']->shipsTo[1]->name->ar)->toBe('البحرين')
            ->and($codes($stores['eg']))->toBe(['EG'])
            ->and(array_map(static fn (StoreSummary $store): int => count($store->shipsTo), $stores))->toHaveCount(3);
    });

    it('offers a Super Admin the countries in use to add, each with its store and its list', function () {
        placesPanelSaudi();
        app(AddAddressCountryHandler::class)->handle(new AddAddressCountry('QA'));
        app(AddAddressCountryHandler::class)->handle(new AddAddressCountry('BH'));
        app(RetireAddressCountryHandler::class)->handle(new RetireAddressCountry('BH'));

        $offered = [];

        foreach (app(ListStoresHandler::class)->countriesToAdd('en') as $row) {
            $offered[$row->code] = [$row->store?->code, $row->hasPlaces];
        }

        expect($offered)->toBe(['EG' => ['eg', false], 'QA' => [null, false], 'SA' => ['sa', true], 'AE' => ['ae', false]]);
    });

    it('shows the ship-to countries to everyone who may see the stores, and offers them nothing to add', function () {
        Fx::actAsAdmin(['sa'], [PlatformPermissions::STORE_UPDATE, PlatformPermissions::STORE_VIEW]);

        $stores = placesPanelStores();

        expect(array_map(static fn (ShipsToCountry $country): string => $country->code, $stores['sa']->shipsTo))->toBe(['SA'])
            ->and(app(ListStoresHandler::class)->countriesToAdd('en'))->toBe([])
            ->and(app(ListStoresHandler::class)->mayChangeCountries())->toBeFalse();
    });

    it('reads the stores with their countries in a fixed number of statements', function () {
        // The ship-to lists; the stores are cached.
        expect(placesPanelQueries(fn () => placesPanelStores()))->toBe(1);
    });
});
