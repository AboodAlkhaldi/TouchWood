<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Platform\Application\Command\AddAddressCountry\AddAddressCountry;
use Modules\Platform\Application\Command\AddAddressCountry\AddAddressCountryHandler;
use Modules\Platform\Application\Command\AddPlace\AddPlace;
use Modules\Platform\Application\Command\AddPlace\AddPlaceHandler;
use Modules\Platform\Application\Command\AddStoreCountry\AddStoreCountry;
use Modules\Platform\Application\Command\AddStoreCountry\AddStoreCountryHandler;
use Modules\Platform\Application\Command\CreateStore\CreateStore;
use Modules\Platform\Application\Command\CreateStore\CreateStoreHandler;
use Modules\Platform\Application\Command\RemoveStoreCountry\RemoveStoreCountry;
use Modules\Platform\Application\Command\RemoveStoreCountry\RemoveStoreCountryHandler;
use Modules\Platform\Application\Command\RenamePlace\RenamePlace;
use Modules\Platform\Application\Command\RenamePlace\RenamePlaceHandler;
use Modules\Platform\Application\Command\RestoreAddressCountry\RestoreAddressCountry;
use Modules\Platform\Application\Command\RestoreAddressCountry\RestoreAddressCountryHandler;
use Modules\Platform\Application\Command\RestorePlace\RestorePlace;
use Modules\Platform\Application\Command\RestorePlace\RestorePlaceHandler;
use Modules\Platform\Application\Command\RetireAddressCountry\RetireAddressCountry;
use Modules\Platform\Application\Command\RetireAddressCountry\RetireAddressCountryHandler;
use Modules\Platform\Application\Command\RetirePlace\RetirePlace;
use Modules\Platform\Application\Command\RetirePlace\RetirePlaceHandler;
use Modules\Platform\Domain\Exception\AddressCountryInUse;
use Modules\Platform\Domain\Exception\AddressCountryNotFound;
use Modules\Platform\Domain\Exception\AddressCountryRetired;
use Modules\Platform\Domain\Exception\CountryShippedByAnotherStore;
use Modules\Platform\Domain\Exception\CountryWithoutPlaces;
use Modules\Platform\Domain\Exception\InvalidPlace;
use Modules\Platform\Domain\Exception\PlaceNameTaken;
use Modules\Platform\Domain\Exception\PlaceNotFound;
use Modules\Platform\Domain\Exception\PlaceRetired;
use Modules\Platform\Domain\Exception\StoreOwnCountry;
use Modules\Platform\Domain\Repository\StoreCountryRepository;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Contracts\ShipToCountryGuard;
use Modules\Platform\Public\Contracts\ShipToCountryGuards;
use Modules\Platform\Public\Dto\AddressCountryDto;
use Modules\Platform\Public\Dto\PlaceDto;
use Modules\Platform\Public\Dto\TranslatedTextDto;
use Modules\Platform\Public\Enums\PlaceKind;
use Shared\Domain\Error\DomainError;
use Shared\Domain\Error\ErrorCategory;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    Fx::actAsStaff(Fx::staff(superAdmin: true));
});

/*
| The places and each store's ship-to countries (platform.md, stage 7 (a) and (b); shipping.md §1.3,
| §9.3 #4 and #9) with the owner's answers to step 1's eight questions (2026-10-28, all as
| recommended).
|
| Every helper is named after this file's subject: a function in a Pest file is global to the suite.
*/

function placesApi(): PlatformApi
{
    return app(PlatformApi::class);
}

function placesAdd(string $country, PlaceKind $kind, ?string $parent, string $ar, string $en): string
{
    return app(AddPlaceHandler::class)->handle(new AddPlace($country, $kind->value, $parent, $ar, $en));
}

/**
 * A region with one city in it.
 *
 * @return array{region: string, city: string}
 */
function placesRegionWithCity(string $country = 'SA', string $regionEn = 'Riyadh Region', string $cityEn = 'Riyadh'): array
{
    $region = placesAdd($country, PlaceKind::Region, null, 'منطقة '.$regionEn, $regionEn);

    return ['region' => $region, 'city' => placesAdd($country, PlaceKind::City, $region, 'مدينة '.$cityEn, $cityEn)];
}

/**
 * @param  list<PlaceDto|AddressCountryDto>  $items
 * @return list<string>
 */
function placesNames(array $items, string $locale = 'en'): array
{
    return array_map(static fn (PlaceDto|AddressCountryDto $item): string => $item->name->in($locale), $items);
}

function placesStoreId(string $code): StoreId
{
    return StoreId::fromString((string) DB::table('platform.stores')->where('code', $code)->value('id'));
}

function placesAuditCount(string $action, ?string $subjectId = null): int
{
    return DB::table('platform.audit_entries')->where('action', $action)->when($subjectId !== null, fn ($query) => $query->where('subject_id', $subjectId))->count();
}

describe('every store ships to its own country', function () {
    it('puts each seeded store on its own country, which is on the address list with no places yet', function () {
        expect(placesApi()->storeCountries(placesStoreId('sa')))->toBe(['SA'])
            ->and(placesApi()->storeCountries(placesStoreId('eg')))->toBe(['EG'])
            ->and(placesApi()->countryStore('EG')?->code)->toBe('eg')
            ->and(placesApi()->countryStore('QA'))->toBeNull();

        $countries = placesApi()->addressCountries('en');

        expect(array_map(static fn (AddressCountryDto $c): string => $c->code, $countries))->toEqualCanonicalizing(['SA', 'EG', 'AE'])
            ->and(array_unique(array_map(static fn (AddressCountryDto $c): bool => $c->hasPlaces, $countries)))->toBe([false])
            ->and(collect($countries)->firstWhere('code', 'SA')?->name->en)->toBe('Saudi Arabia');
    });

    it('counts an off store\'s countries as its own (shipping.md §1.2)', function () {
        DB::table('platform.stores')->where('code', 'eg')->update(['is_active' => false]);

        expect(placesApi()->countryStore('EG')?->code)->toBe('eg');
    });

    it('refuses opening a store in a country another store ships to, and makes nothing (Q1)', function () {
        DB::table('platform.currencies')->insert(['code' => 'XTS', 'exponent' => 2, 'name' => json_encode(['ar' => 'عملة', 'en' => 'Currency']), 'abbreviation' => json_encode(['ar' => 'ع', 'en' => 'XTS']), 'created_at' => now(), 'updated_at' => now()]);

        expect(fn () => app(CreateStoreHandler::class)->handle(new CreateStore('sb', 'متجر', 'Second Saudi', 'SA', 'XTS', 1500, 'Asia/Riyadh', 9)))
            ->toThrow(CountryShippedByAnotherStore::class)
            ->and(DB::table('platform.stores')->where('code', 'sb')->exists())->toBeFalse();
    });

    it('puts a new store\'s country on the address list and on its own list, bringing a retired one back', function () {
        DB::table('platform.address_countries')->insert(['country_code' => 'KW', 'retired' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('platform.currencies')->insert(['code' => 'XTS', 'exponent' => 2, 'name' => json_encode(['ar' => 'عملة', 'en' => 'Currency']), 'abbreviation' => json_encode(['ar' => 'ع', 'en' => 'XTS']), 'created_at' => now(), 'updated_at' => now()]);

        app(CreateStoreHandler::class)->handle(new CreateStore('kw', 'الكويت', 'Kuwait', 'KW', 'XTS', 0, 'Asia/Kuwait', 9));

        expect(placesApi()->storeCountries(placesStoreId('kw')))->toBe(['KW'])
            ->and(DB::table('platform.address_countries')->where('country_code', 'KW')->value('retired'))->toBeFalse()
            ->and(placesAuditCount('platform.address_country.restored'))->toBe(1)
            ->and(placesAuditCount('platform.store_country.added', placesStoreId('kw')->value))->toBe(1);
    });
});

describe('the address countries', function () {
    it('adds a country once, and leaves it alone when it is already there', function () {
        app(AddAddressCountryHandler::class)->handle(new AddAddressCountry('qa'));
        app(AddAddressCountryHandler::class)->handle(new AddAddressCountry('QA'));

        expect(DB::table('platform.address_countries')->where('country_code', 'QA')->count())->toBe(1)
            ->and(placesAuditCount('platform.address_country.added', 'QA'))->toBe(1);
    });

    it('refuses a code that is no ISO country', function () {
        expect(fn () => app(AddAddressCountryHandler::class)->handle(new AddAddressCountry('ZZ')))->toThrow(InvalidPlace::class);
    });

    it('refuses retiring a country a store ships to (Q2)', function () {
        expect(fn () => app(RetireAddressCountryHandler::class)->handle(new RetireAddressCountry('EG')))->toThrow(AddressCountryInUse::class);
    });

    it('retires and restores a country, which leaves and rejoins the list, and refuses adding it while retired (Q3)', function () {
        app(AddAddressCountryHandler::class)->handle(new AddAddressCountry('QA'));
        app(RetireAddressCountryHandler::class)->handle(new RetireAddressCountry('QA'));

        expect(array_column(array_map(static fn (AddressCountryDto $c): array => ['code' => $c->code], placesApi()->addressCountries('en')), 'code'))->not->toContain('QA')
            ->and(fn () => app(AddAddressCountryHandler::class)->handle(new AddAddressCountry('QA')))->toThrow(AddressCountryRetired::class);

        app(RestoreAddressCountryHandler::class)->handle(new RestoreAddressCountry('QA'));

        expect(array_column(array_map(static fn (AddressCountryDto $c): array => ['code' => $c->code], placesApi()->addressCountries('en')), 'code'))->toContain('QA')
            ->and(placesAuditCount('platform.address_country.retired'))->toBe(1)
            ->and(placesAuditCount('platform.address_country.restored'))->toBe(1);
    });

    it('names an unknown country as not on the list', function () {
        expect(fn () => app(RetireAddressCountryHandler::class)->handle(new RetireAddressCountry('QA')))->toThrow(AddressCountryNotFound::class);
    });
});

describe('the places', function () {
    it('builds regions, cities and districts, read by id with the places above them', function () {
        ['region' => $region, 'city' => $city] = placesRegionWithCity();
        $district = placesAdd('SA', PlaceKind::District, $city, 'العليا', 'Al Olaya');

        $place = placesApi()->place([$district, 'not-an-id'])[$district];

        expect($place->kind)->toBe(PlaceKind::District)
            ->and($place->regionId)->toBe($region)
            ->and($place->cityId)->toBe($city)
            ->and($place->retired)->toBeFalse()
            ->and(placesNames(placesApi()->districts($city, 'en')))->toBe(['Al Olaya'])
            ->and(collect(placesApi()->addressCountries('en'))->firstWhere('code', 'SA')?->hasPlaces)->toBeTrue();
    });

    it('refuses a parent of the wrong kind or of another country', function (PlaceKind $kind, Closure $parent) {
        expect(fn () => placesAdd('SA', $kind, $parent(), 'مكان', 'Place'))->toThrow(InvalidPlace::class);
    })->with([
        'a region with a parent' => [PlaceKind::Region, fn () => placesRegionWithCity()['region']],
        'a city without a region' => [PlaceKind::City, fn () => null],
        'a city in a city' => [PlaceKind::City, fn () => placesRegionWithCity()['city']],
        'a district in a region' => [PlaceKind::District, fn () => placesRegionWithCity()['region']],
        'a city in another country\'s region' => [PlaceKind::City, fn () => placesRegionWithCity('EG', 'Cairo Governorate', 'Cairo')['region']],
    ]);

    it('refuses an unknown parent, an unknown kind and a name over 100 characters', function () {
        expect(fn () => placesAdd('SA', PlaceKind::City, '01jbz0000000000000000000aa', 'مكان', 'Place'))->toThrow(PlaceNotFound::class)
            ->and(fn () => app(AddPlaceHandler::class)->handle(new AddPlace('SA', 'TOWN', null, 'مكان', 'Place')))->toThrow(InvalidPlace::class)
            ->and(fn () => placesAdd('SA', PlaceKind::Region, null, 'م', str_repeat('a', 101)))->toThrow(InvalidPlace::class);
    });

    it('refuses a name already used under the same parent, in either language and either case (Q6)', function () {
        ['region' => $region] = placesRegionWithCity();

        expect(fn () => placesAdd('SA', PlaceKind::City, $region, 'مدينة أخرى', 'riyadh'))->toThrow(PlaceNameTaken::class)
            ->and(fn () => placesAdd('SA', PlaceKind::City, $region, 'مدينة Riyadh', 'Another'))->toThrow(PlaceNameTaken::class);

        // Another region may have a city of that name.
        $other = placesAdd('SA', PlaceKind::Region, null, 'منطقة مكة', 'Makkah Region');
        expect(placesAdd('SA', PlaceKind::City, $other, 'مدينة Riyadh', 'Riyadh'))->toBeString();
    });

    it('refuses a taken name in the handler, before the database would', function () {
        ['region' => $region] = placesRegionWithCity();
        DB::statement('DROP INDEX platform.places_unique_name_ar');
        DB::statement('DROP INDEX platform.places_unique_name_en');

        expect(fn () => placesAdd('SA', PlaceKind::City, $region, 'مدينة أخرى', 'RIYADH'))->toThrow(PlaceNameTaken::class);

        $other = placesAdd('SA', PlaceKind::City, $region, 'الخرج', 'Al Kharj');
        expect(fn () => app(RenamePlaceHandler::class)->handle(new RenamePlace($other, 'الخرج', 'riyadh')))->toThrow(PlaceNameTaken::class);
    });

    it('is refused by the database too, behind the handler', function () {
        ['region' => $region] = placesRegionWithCity();

        expect(fn () => DB::table('platform.places')->insert(['id' => '01jbz0000000000000000000ab', 'country_code' => 'SA', 'kind' => 'CITY', 'parent_id' => $region, 'name' => json_encode(['ar' => 'x', 'en' => 'RIYADH']), 'retired' => false]))
            ->toThrow(QueryException::class);
    });

    it('holds a place\'s kind, parent and names in the database too', function (Closure $row) {
        ['region' => $region] = placesRegionWithCity();

        expect(fn () => DB::table('platform.places')->insert(array_merge(
            ['id' => '01jbz0000000000000000000ac', 'country_code' => 'SA', 'kind' => 'CITY', 'parent_id' => $region, 'name' => json_encode(['ar' => 'س', 'en' => 'S']), 'retired' => false],
            $row($region),
        )))->toThrow(QueryException::class);
    })->with([
        'an unknown kind' => [fn (string $region) => ['kind' => 'TOWN']],
        'a region with a parent' => [fn (string $region) => ['kind' => 'REGION']],
        'a city without a parent' => [fn (string $region) => ['parent_id' => null]],
        'an English name missing' => [fn (string $region) => ['name' => json_encode(['ar' => 'س'])]],
        'a blank Arabic name' => [fn (string $region) => ['name' => json_encode(['ar' => ' ', 'en' => 'S'])]],
        'a country not on the list' => [fn (string $region) => ['country_code' => 'QA']],
    ]);

    it('holds an address country\'s code to two capital letters in the database too', function () {
        expect(fn () => DB::table('platform.address_countries')->insert(['country_code' => 'qa', 'retired' => false]))->toThrow(QueryException::class);
    });

    it('saves Arabic-Indic digits in Latin', function () {
        $region = placesAdd('SA', PlaceKind::Region, null, 'الحي ٣', 'District ۷');

        expect(placesApi()->place([$region])[$region]->name)->toEqual(new TranslatedTextDto('الحي 3', 'District 7'));
    });

    it('refuses adding in a country not on the list or retired', function () {
        expect(fn () => placesAdd('QA', PlaceKind::Region, null, 'الدوحة', 'Doha'))->toThrow(AddressCountryNotFound::class);

        app(AddAddressCountryHandler::class)->handle(new AddAddressCountry('QA'));
        app(RetireAddressCountryHandler::class)->handle(new RetireAddressCountry('QA'));

        expect(fn () => placesAdd('QA', PlaceKind::Region, null, 'الدوحة', 'Doha'))->toThrow(AddressCountryRetired::class);
    });

    it('sorts every list by name as the page\'s language sorts (Q7)', function () {
        // Added in neither language's order, so a list in the order added would fail both.
        placesAdd('SA', PlaceKind::Region, null, 'ينبع', 'Mid');
        placesAdd('SA', PlaceKind::Region, null, 'أبها', 'Zahran');
        placesAdd('SA', PlaceKind::Region, null, 'تبوك', 'Abha');

        expect(placesNames(placesApi()->regions('SA', 'en')))->toBe(['Abha', 'Mid', 'Zahran'])
            ->and(placesNames(placesApi()->regions('SA', 'ar'), 'ar'))->toBe(['أبها', 'تبوك', 'ينبع'])
            // A code typed in lower case reads the same list.
            ->and(placesNames(placesApi()->regions('sa', 'en')))->toBe(['Abha', 'Mid', 'Zahran']);
    });

    it('renames a place, refusing a name its siblings use', function () {
        ['region' => $region, 'city' => $city] = placesRegionWithCity();
        placesAdd('SA', PlaceKind::City, $region, 'الخرج', 'Al Kharj');

        expect(fn () => app(RenamePlaceHandler::class)->handle(new RenamePlace($city, 'الخرج', 'Kharj')))->toThrow(PlaceNameTaken::class);

        app(RenamePlaceHandler::class)->handle(new RenamePlace($city, 'الرياض', 'Ar Riyad'));

        expect(placesApi()->place([$city])[$city]->name->en)->toBe('Ar Riyad')
            ->and(placesAuditCount('platform.place.renamed'))->toBe(1);
    });
});

describe('retiring and restoring places', function () {
    it('hides a retired region\'s cities and districts, keeps them by id, and brings them back on restore (Q3, Q4)', function () {
        ['region' => $region, 'city' => $city] = placesRegionWithCity();
        $district = placesAdd('SA', PlaceKind::District, $city, 'العليا', 'Al Olaya');

        app(RetirePlaceHandler::class)->handle(new RetirePlace($region));

        expect(placesApi()->regions('SA', 'en'))->toBe([])
            ->and(placesApi()->cities($region, 'en'))->toBe([])
            ->and(placesApi()->citiesOf('SA', 'en'))->toBe([])
            ->and(placesApi()->districts($city, 'en'))->toBe([])
            ->and(placesApi()->place([$district])[$district]->retired)->toBeTrue()
            ->and(DB::table('platform.places')->where('id', $district)->value('retired'))->toBeFalse()
            ->and(collect(placesApi()->addressCountries('en'))->firstWhere('code', 'SA')?->hasPlaces)->toBeFalse();

        app(RestorePlaceHandler::class)->handle(new RestorePlace($region));

        expect(placesNames(placesApi()->districts($city, 'en')))->toBe(['Al Olaya'])
            ->and(placesAuditCount('platform.place.retired'))->toBe(1)
            ->and(placesAuditCount('platform.place.restored'))->toBe(1);
    });

    it('keeps a place retired on its own hidden when its region comes back', function () {
        ['region' => $region, 'city' => $city] = placesRegionWithCity();

        app(RetirePlaceHandler::class)->handle(new RetirePlace($city));
        app(RetirePlaceHandler::class)->handle(new RetirePlace($region));
        app(RestorePlaceHandler::class)->handle(new RestorePlace($region));

        expect(placesApi()->cities($region, 'en'))->toBe([]);
    });

    it('refuses adding under a retired place, or under a city whose region is retired', function () {
        ['region' => $region, 'city' => $city] = placesRegionWithCity();
        app(RetirePlaceHandler::class)->handle(new RetirePlace($region));

        expect(fn () => placesAdd('SA', PlaceKind::City, $region, 'جدة', 'Jeddah'))->toThrow(PlaceRetired::class)
            ->and(fn () => placesAdd('SA', PlaceKind::District, $city, 'العليا', 'Al Olaya'))->toThrow(PlaceRetired::class);
    });

    it('hides every place of a retired country', function () {
        app(AddAddressCountryHandler::class)->handle(new AddAddressCountry('QA'));
        ['city' => $city] = placesRegionWithCity('QA', 'Doha Municipality', 'Doha');
        app(RetireAddressCountryHandler::class)->handle(new RetireAddressCountry('QA'));

        expect(placesApi()->citiesOf('QA', 'en'))->toBe([])
            ->and(placesApi()->place([$city])[$city]->retired)->toBeTrue();
    });

    it('leaves a place already in that state alone, writing nothing', function () {
        ['city' => $city] = placesRegionWithCity();

        app(RestorePlaceHandler::class)->handle(new RestorePlace($city));

        expect(placesAuditCount('platform.place.restored'))->toBe(0);
    });
});

describe('the migration, over stores that already exist', function () {
    /**
     * The three tables dropped and the migration run again, over the seeded stores - inside the
     * test's transaction, which PostgreSQL rolls back with the DDL.
     */
    function placesMigrateAgain(): void
    {
        $migration = require base_path('src/Modules/Platform/Infrastructure/Persistence/Migrations/2026_10_28_100000_create_platform_places_tables.php');
        // CASCADE drops only the keys other modules hold on them (Shipping's prices name places).
        DB::statement('DROP TABLE platform.store_countries, platform.places, platform.address_countries CASCADE');
        $migration->up();
    }

    it('puts every store on its own country, and each country on the address list', function () {
        placesMigrateAgain();

        expect(DB::table('platform.address_countries')->orderBy('country_code')->pluck('country_code')->all())->toBe(['AE', 'EG', 'SA'])
            ->and(placesApi()->storeCountries(placesStoreId('sa')))->toBe(['SA'])
            ->and(placesApi()->countryStore('AE')?->code)->toBe('ae');
    });

    it('stops, naming them, when two stores share a country', function () {
        DB::table('platform.stores')->where('code', 'ae')->update(['country_code' => 'SA']);

        expect(fn () => placesMigrateAgain())->toThrow(RuntimeException::class, 'SA: ae, sa');
    });
});

describe('the ship-to countries', function () {
    it('adds a country only once it has a region in use (owner, 2026-10-29)', function () {
        app(AddAddressCountryHandler::class)->handle(new AddAddressCountry('QA'));

        expect(fn () => app(AddStoreCountryHandler::class)->handle(new AddStoreCountry('sa', 'QA')))->toThrow(CountryWithoutPlaces::class);

        // A region is a list, its cities typed; a retired region is none, a city under it neither.
        $region = placesAdd('QA', PlaceKind::Region, null, 'الدوحة', 'Doha Municipality');
        placesAdd('QA', PlaceKind::City, $region, 'الدوحة', 'Doha');
        app(RetirePlaceHandler::class)->handle(new RetirePlace($region));
        expect(fn () => app(AddStoreCountryHandler::class)->handle(new AddStoreCountry('sa', 'QA')))->toThrow(CountryWithoutPlaces::class);

        app(RestorePlaceHandler::class)->handle(new RestorePlace($region));
        app(AddStoreCountryHandler::class)->handle(new AddStoreCountry('sa', 'qa'));
        app(AddStoreCountryHandler::class)->handle(new AddStoreCountry('sa', 'QA'));

        expect(placesApi()->storeCountries(placesStoreId('sa')))->toBe(['QA', 'SA'])
            ->and(placesApi()->countryStore('QA')?->code)->toBe('sa')
            ->and(placesAuditCount('platform.store_country.added'))->toBe(4);
    });

    it('adds a country whose list stops at its regions, with no city at all (owner, 2026-10-29)', function () {
        app(AddAddressCountryHandler::class)->handle(new AddAddressCountry('BH'));
        placesAdd('BH', PlaceKind::Region, null, 'محافظة العاصمة', 'Capital Governorate');

        app(AddStoreCountryHandler::class)->handle(new AddStoreCountry('sa', 'BH'));

        expect(placesApi()->countryStore('BH')?->code)->toBe('sa');
    });

    it('refuses a country another store ships to, a retired one and one not on the list (R3, Q1)', function () {
        placesRegionWithCity('EG', 'Cairo Governorate', 'Cairo');
        app(AddAddressCountryHandler::class)->handle(new AddAddressCountry('QA'));
        app(RetireAddressCountryHandler::class)->handle(new RetireAddressCountry('QA'));

        expect(fn () => app(AddStoreCountryHandler::class)->handle(new AddStoreCountry('sa', 'EG')))->toThrow(CountryShippedByAnotherStore::class)
            ->and(fn () => app(AddStoreCountryHandler::class)->handle(new AddStoreCountry('sa', 'QA')))->toThrow(AddressCountryRetired::class)
            ->and(fn () => app(AddStoreCountryHandler::class)->handle(new AddStoreCountry('sa', 'OM')))->toThrow(AddressCountryNotFound::class);
    });

    it('refuses a country another store ships to in the handler, before the database would', function () {
        placesRegionWithCity('EG', 'Cairo Governorate', 'Cairo');
        DB::statement('ALTER TABLE platform.store_countries DROP CONSTRAINT store_countries_one_store_per_country');
        DB::table('platform.currencies')->insert(['code' => 'XTS', 'exponent' => 2, 'name' => json_encode(['ar' => 'عملة', 'en' => 'Currency']), 'abbreviation' => json_encode(['ar' => 'ع', 'en' => 'XTS']), 'created_at' => now(), 'updated_at' => now()]);

        expect(fn () => app(AddStoreCountryHandler::class)->handle(new AddStoreCountry('sa', 'EG')))->toThrow(CountryShippedByAnotherStore::class)
            ->and(fn () => app(CreateStoreHandler::class)->handle(new CreateStore('eb', 'متجر', 'Second Egypt', 'EG', 'XTS', 1400, 'Africa/Cairo', 9)))->toThrow(CountryShippedByAnotherStore::class)
            ->and(DB::table('platform.store_countries')->where('country_code', 'EG')->count())->toBe(1);
    });

    it('names the other store when the database refuses a race the handler could not see', function () {
        // Past the handler, as the second of two at once would be: the key refuses, and the store
        // that won is still named, the transaction still usable.
        $refused = null;

        DB::transaction(function () use (&$refused): void {
            try {
                app(StoreCountryRepository::class)->add(placesStoreId('sa'), 'EG');
            } catch (CountryShippedByAnotherStore $e) {
                $refused = $e;
            }

            DB::table('platform.store_countries')->count();
        });

        expect($refused?->storeCode)->toBe('eg');
    });

    it('is held to one store per country by the database too', function () {
        expect(fn () => DB::table('platform.store_countries')->insert(['store_id' => placesStoreId('sa')->value, 'country_code' => 'EG', 'created_at' => now()]))
            ->toThrow(QueryException::class);
    });

    it('never takes a store\'s own country off its list', function () {
        expect(fn () => app(RemoveStoreCountryHandler::class)->handle(new RemoveStoreCountry('sa', 'SA')))->toThrow(StoreOwnCountry::class);
    });

    it('moves a country from one store to another: off the first, then onto the second', function () {
        app(AddAddressCountryHandler::class)->handle(new AddAddressCountry('QA'));
        placesRegionWithCity('QA', 'Doha Municipality', 'Doha');
        app(AddStoreCountryHandler::class)->handle(new AddStoreCountry('sa', 'QA'));

        app(RemoveStoreCountryHandler::class)->handle(new RemoveStoreCountry('sa', 'QA'));
        app(AddStoreCountryHandler::class)->handle(new AddStoreCountry('ae', 'QA'));

        expect(placesApi()->storeCountries(placesStoreId('sa')))->toBe(['SA'])
            ->and(placesApi()->countryStore('QA')?->code)->toBe('ae')
            ->and(placesAuditCount('platform.store_country.removed'))->toBe(1);
    });

    it('asks every registered guard first, and keeps the country when one refuses', function () {
        app(AddAddressCountryHandler::class)->handle(new AddAddressCountry('QA'));
        placesRegionWithCity('QA', 'Doha Municipality', 'Doha');
        app(AddStoreCountryHandler::class)->handle(new AddStoreCountry('sa', 'QA'));

        $refusal = new class('A carrier ships there.') extends DomainError
        {
            public function type(): string
            {
                return 'test.carrier_there';
            }

            public function category(): ErrorCategory
            {
                return ErrorCategory::Conflict;
            }

            public function context(): array
            {
                return [];
            }
        };
        app()->instance('places.test.refusal', $refusal);
        PlacesRefusingGuard::$asked = [];
        app(ShipToCountryGuards::class)->register(PlacesRefusingGuard::class);

        expect(fn () => app(RemoveStoreCountryHandler::class)->handle(new RemoveStoreCountry('sa', 'QA')))->toThrow($refusal::class)
            ->and(placesApi()->countryStore('QA')?->code)->toBe('sa')
            ->and(PlacesRefusingGuard::$asked)->toBe([[placesStoreId('sa')->value, 'QA']]);
    });

    it('leaves a country not on the store\'s list alone', function () {
        app(RemoveStoreCountryHandler::class)->handle(new RemoveStoreCountry('sa', 'EG'));
        app(RemoveStoreCountryHandler::class)->handle(new RemoveStoreCountry('sa', 'OM'));

        expect(placesApi()->countryStore('EG')?->code)->toBe('eg')
            ->and(placesAuditCount('platform.store_country.removed'))->toBe(0);
    });
});

final class PlacesRefusingGuard implements ShipToCountryGuard
{
    /** @var list<array{string, string}> */
    public static array $asked = [];

    public function check(StoreId $store, string $countryCode): void
    {
        self::$asked[] = [$store->value, $countryCode];

        throw app('places.test.refusal');
    }
}
