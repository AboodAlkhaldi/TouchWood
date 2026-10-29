<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Modules\Platform\Application\Command\AddPlace\AddPlace;
use Modules\Platform\Application\Command\AddPlace\AddPlaceHandler;
use Modules\Platform\Application\Command\RetirePlace\RetirePlace;
use Modules\Platform\Application\Command\RetirePlace\RetirePlaceHandler;
use Shared\Application\ActorContext;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\seed;

/*
| The address form on Platform's places (frontend.md §3.6, F9 on Platform's places; §8.4, the owner's
| answers of 2026-10-29, all (a); access.md amendment 76). Access's rules are tested in
| AddressPlacesTest; these are about the page: the countries it offers and where each belongs, the
| places a saved address carries back to its form, the lists read as the customer picks, and the save
| passing the picks on.
|
| Every helper is named after this file's subject: a function in a Pest file is global to the suite.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

/**
 * Work done as a Super Admin, then the ActorContext given back (as Fx::customer does), so the
 * requests that follow read who is acting from their session.
 *
 * @template TResult
 *
 * @param  callable(): TResult  $work
 * @return TResult
 */
function addressFormAsSuperAdmin(callable $work): mixed
{
    $previous = app()->getBindings()[ActorContext::class]['concrete'] ?? null;
    Fx::actAsStaff(Fx::staff(superAdmin: true));

    try {
        return $work();
    } finally {
        app()->scoped(ActorContext::class, $previous);
        app()->forgetScopedInstances();
    }
}

/**
 * KSA's places.
 *
 * @return array{region: string, city: string, district: string, empty_city: string, other_region: string}
 */
function addressFormPlaces(): array
{
    return addressFormAsSuperAdmin(function (): array {
        $add = fn (string $kind, ?string $parent, string $ar, string $en): string => app(AddPlaceHandler::class)->handle(new AddPlace('SA', $kind, $parent, $ar, $en));

        $region = $add('REGION', null, 'منطقة الرياض', 'Riyadh Region');
        $city = $add('CITY', $region, 'الرياض', 'Riyadh');
        $district = $add('DISTRICT', $city, 'العليا', 'Al Olaya');
        $emptyCity = $add('CITY', $region, 'الخرج', 'Al Kharj');
        $otherRegion = $add('REGION', null, 'منطقة مكة', 'Makkah Region');

        return ['region' => $region, 'city' => $city, 'district' => $district, 'empty_city' => $emptyCity, 'other_region' => $otherRegion];
    });
}

function addressFormRetire(string $place): void
{
    addressFormAsSuperAdmin(fn () => app(RetirePlaceHandler::class)->handle(new RetirePlace($place)));
}

/**
 * @return array{0: string, 1: AdminBrowser}
 */
function addressFormCustomer(): array
{
    $customerId = Fx::customer();
    $email = (string) DB::table('access.customers')->where('id', $customerId)->value('email');

    $browser = new AdminBrowser;
    $browser->post('/sa/en/account/sign-in', ['email' => $email, 'password' => Fx::CUSTOMER_PASSWORD])->assertRedirect('/sa/en');

    return [$customerId, $browser];
}

/**
 * @param  array<string, mixed>  $extra
 * @return array<string, mixed>
 */
function addressFormSent(array $extra = []): array
{
    return [
        'store_id' => Fx::storeId('sa'),
        'label' => 'Home',
        'recipient_name' => 'Sara Ali',
        'phone' => '+966512345678',
        'fields' => ['street' => 'King Fahd Road', 'building' => '7'],
        ...$extra,
    ];
}

/** The queries one request runs, after a first one has warmed the session. */
function addressFormQueries(AdminBrowser $browser, string $url): int
{
    $browser->get($url)->assertOk();
    $count = 0;
    DB::listen(function () use (&$count): void {
        $count++;
    });
    $browser->get($url)->assertOk();

    return $count;
}

describe('the countries offered, read when the form opens (Q1, Q2, Q3; the owner, 2026-10-29)', function () {
    it('offers the address countries, the store\'s own first, and says which another store serves', function () {
        addressFormPlaces();
        [, $browser] = addressFormCustomer();
        // Qatar: on the list, shipped to by no store (Q3).
        DB::table('platform.address_countries')->insert(['country_code' => 'QA', 'retired' => false, 'created_at' => now(), 'updated_at' => now()]);

        // The page itself carries none of it: only the store's own country, where a new address starts.
        $browser->get('/sa/en/account?tab=addresses')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('addresses.0.storeCode', 'sa')
                ->where('addresses.0.countryCode', 'SA')
                ->missing('addresses.0.countries')
            );

        $countries = $browser->get('/sa/en/account/address-form?book=sa')->assertOk()->collect('countries');

        expect($countries->first())->toMatchArray(['code' => 'SA', 'hasPlaces' => true, 'servedBy' => null])
            ->and($countries->pluck('code')->sort()->values()->all())->toBe(['AE', 'EG', 'QA', 'SA'])
            // Egypt belongs to the Egypt store: named, in the page's language (Q2).
            ->and($countries->firstWhere('code', 'EG')['servedBy'])->toBe(['code' => 'eg', 'name' => 'Egypt', 'open' => true])
            ->and($countries->firstWhere('code', 'QA')['servedBy'])->toBeNull()
            ->and($countries->firstWhere('code', 'QA')['otherName'])->toBe('قطر');

        // The Egypt store's own form starts on Egypt, and points Saudi Arabia to its store.
        $egypt = $browser->get('/sa/en/account/address-form?book=eg')->assertOk()->collect('countries');
        expect($egypt->first()['code'])->toBe('EG')
            ->and($egypt->firstWhere('code', 'SA')['servedBy']['code'])->toBe('sa');
    });

    it('brings the own country\'s regions with the form, and none where it has no list', function () {
        $places = addressFormPlaces();
        [, $browser] = addressFormCustomer();

        $browser->get('/sa/en/account/address-form?book=sa')->assertOk()
            ->assertJsonPath('regions.0.name', 'Makkah Region')
            ->assertJsonPath('regions.1.id', $places['region'])
            ->assertJsonPath('regions.1.otherName', 'منطقة الرياض');
        $browser->get('/sa/en/account/address-form?book=eg')->assertOk()->assertJsonPath('regions', []);
    });

    it('says a switched-off store is closed rather than linking to it, and has no form of its own', function () {
        [, $browser] = addressFormCustomer();
        DB::table('platform.stores')->where('code', 'eg')->update(['is_active' => false]);
        // Platform keeps the stores in its cache: changed underneath it, it is read again.
        Cache::flush();

        $countries = $browser->get('/sa/en/account/address-form?book=sa')->assertOk()->collect('countries');
        expect($countries->firstWhere('code', 'EG')['servedBy'])->toBe(['code' => 'eg', 'name' => 'Egypt', 'open' => false]);

        $browser->get('/sa/en/account/address-form?book=eg')->assertNotFound();
        $browser->get('/sa/en/account/address-form?book=zz')->assertNotFound();
        $browser->get('/sa/en/account/address-form')->assertNotFound();
    });

    it('is for a signed-in customer only', function () {
        (new AdminBrowser)->get('/sa/en/account/address-form?book=sa')->assertRedirect();
    });
});

describe('a saved address carries its places back to the form (Q8, Q9, Q10)', function () {
    it('carries the picked ids and marks a place retired since', function () {
        $places = addressFormPlaces();
        [, $browser] = addressFormCustomer();

        $browser->post('/sa/en/account/addresses', addressFormSent([
            'country' => 'SA', 'region_id' => $places['region'], 'city_id' => $places['city'], 'district_id' => $places['district'],
        ]))->assertRedirect();

        addressFormRetire($places['district']);

        $browser->get('/sa/en/account?tab=addresses')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('addresses.0.addresses.0.countryCode', 'SA')
                ->where('addresses.0.addresses.0.regionId', $places['region'])
                ->where('addresses.0.addresses.0.cityId', $places['city'])
                ->where('addresses.0.addresses.0.districtId', $places['district'])
                // The names are the address's own text, in the language it was saved in.
                ->where('addresses.0.addresses.0.fields.city', 'Riyadh')
                ->where('addresses.0.addresses.0.retiredPlaces', [$places['district']])
                ->where('addresses.0.addresses.0.needsPlaces', false)
                ->where('addresses.0.addresses.0.isComplete', true)
            );
    });

    it('marks a typed address whose country has a list now', function () {
        [, $browser] = addressFormCustomer();

        $browser->post('/sa/en/account/addresses', addressFormSent([
            'fields' => ['administrative_area' => 'Riyadh', 'city' => 'Jeddah', 'district' => 'Al Balad', 'street' => 'King Fahd Road', 'building' => '7'],
        ]))->assertRedirect();

        addressFormPlaces();

        $browser->get('/sa/en/account?tab=addresses')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('addresses.0.addresses.0.regionId', null)
                ->where('addresses.0.addresses.0.needsPlaces', true)
                ->where('addresses.0.addresses.0.isComplete', false)
                ->where('addresses.0.addresses.0.fields.city', 'Jeddah')
            );
    });
});

describe('the lists, read as the customer picks (Q7)', function () {
    it('reads a country\'s regions, a region\'s cities and a city\'s districts, in both languages', function () {
        $places = addressFormPlaces();
        [, $browser] = addressFormCustomer();

        $browser->get('/sa/en/account/places?country=sa')->assertOk()
            ->assertJsonPath('places.0.name', 'Makkah Region')
            ->assertJsonPath('places.1.id', $places['region'])
            ->assertJsonPath('places.1.otherName', 'منطقة الرياض');
        $browser->get('/sa/ar/account/places?region='.$places['region'])->assertOk()
            ->assertJsonCount(2, 'places')
            ->assertJsonPath('places.0.name', 'الخرج');
        $browser->get('/sa/en/account/places?city='.$places['city'])->assertOk()->assertExactJson(['places' => [['id' => $places['district'], 'name' => 'Al Olaya', 'otherName' => 'العليا']]]);
        $browser->get('/sa/en/account/places?city='.$places['empty_city'])->assertOk()->assertExactJson(['places' => []]);
    });

    it('leaves out what is retired, and is not found without a level', function () {
        $places = addressFormPlaces();
        addressFormRetire($places['district']);
        [, $browser] = addressFormCustomer();

        $browser->get('/sa/en/account/places?city='.$places['city'])->assertOk()->assertExactJson(['places' => []]);
        $browser->get('/sa/en/account/places')->assertNotFound();
        $browser->get('/sa/en/account/places?country=SAU')->assertNotFound();
    });

    it('is for a signed-in customer only', function () {
        $places = addressFormPlaces();

        (new AdminBrowser)->get('/sa/en/account/places?region='.$places['region'])->assertRedirect();
    });

    it('keeps each read to the session\'s queries and one of its own', function () {
        $places = addressFormPlaces();
        [, $browser] = addressFormCustomer();

        $page = addressFormQueries($browser, '/sa/en/account?tab=addresses');
        $form = addressFormQueries($browser, '/sa/en/account/address-form?book=sa');
        $read = addressFormQueries($browser, '/sa/en/account/places?region='.$places['region']);
        $none = addressFormQueries($browser, '/sa/en/account/places?region=01aaaaaaaaaaaaaaaaaaaaaaaa');

        // Recorded for the PR (owner, 2026-10-19: budgets are targets): the tab 21 - nothing of the
        // places read for an empty book; the form's read 12 when Add or Edit is pressed - the
        // session's 7, the countries, each store's ship-to list and the own regions; a list read 8 -
        // the session's 7 and its own one, as the same read with nothing to find.
        expect($read)->toBe($none)
            ->and($read)->toBeLessThanOrEqual(8)
            ->and($page)->toBeLessThanOrEqual(21)
            ->and($form)->toBeLessThanOrEqual(12);
    });
});

describe('saving the picks', function () {
    it('saves the ids picked and fills their names in the page\'s language', function () {
        $places = addressFormPlaces();
        [$customerId, $browser] = addressFormCustomer();

        $browser->post('/sa/ar/account/addresses', addressFormSent([
            'country' => 'SA', 'region_id' => $places['region'], 'city_id' => $places['city'], 'district_id' => $places['district'],
        ]))->assertRedirect();

        $row = DB::table('access.addresses')->where('customer_id', $customerId)->sole();
        expect($row->region_id)->toBe($places['region'])
            ->and($row->district_id)->toBe($places['district'])
            ->and(json_decode((string) $row->fields, true)['city'])->toBe('الرياض');
    });

    it('takes an "Other" city typed under a picked region (Q5)', function () {
        $places = addressFormPlaces();
        [$customerId, $browser] = addressFormCustomer();

        $browser->post('/sa/en/account/addresses', addressFormSent([
            'country' => 'SA', 'region_id' => $places['region'], 'city_id' => '', 'district_id' => '',
            'fields' => ['city' => 'Diriyah', 'district' => 'Turaif', 'street' => 'King Fahd Road', 'building' => '7'],
        ]))->assertRedirect();

        $row = DB::table('access.addresses')->where('customer_id', $customerId)->sole();
        expect($row->city_id)->toBeNull()
            ->and(json_decode((string) $row->fields, true))->toMatchArray(['administrative_area' => 'Riyadh Region', 'city' => 'Diriyah']);
    });

    it('saves an address in a country no store ships to, typed (Q3)', function () {
        [$customerId, $browser] = addressFormCustomer();
        DB::table('platform.address_countries')->insert(['country_code' => 'QA', 'retired' => false, 'created_at' => now(), 'updated_at' => now()]);

        $browser->post('/sa/en/account/addresses', addressFormSent([
            'country' => 'QA', 'region_id' => '', 'city_id' => '', 'district_id' => '',
            'fields' => ['administrative_area' => 'Doha', 'city' => 'Doha', 'district' => 'West Bay', 'street' => 'Corniche', 'building' => '7'],
        ]))->assertRedirect();

        expect(DB::table('access.addresses')->where('customer_id', $customerId)->value('country_code'))->toBe('QA');
    });

    it('refuses a city of another region, in its own words', function () {
        $places = addressFormPlaces();
        [, $browser] = addressFormCustomer();

        $response = $browser->post('/sa/en/account/addresses', addressFormSent([
            'country' => 'SA', 'region_id' => $places['other_region'], 'city_id' => $places['city'],
        ]))->assertRedirect();

        expect(AdminBrowser::formError($response))->toContain('city');
    });

    it('refuses a country another store serves, in its own words', function () {
        [, $browser] = addressFormCustomer();

        $response = $browser->post('/sa/en/account/addresses', addressFormSent([
            'country' => 'EG', 'fields' => ['administrative_area' => 'Cairo', 'city' => 'Cairo', 'district' => 'Zamalek', 'street' => 'King Fahd Road', 'building' => '7'],
        ]))->assertRedirect();

        expect(AdminBrowser::formError($response))->toContain('Egypt');
    });
});
