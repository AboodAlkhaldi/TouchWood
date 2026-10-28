<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Access\Application\Command\SaveAddress\SaveAddress;
use Modules\Access\Application\Command\SaveAddress\SaveAddressHandler;
use Modules\Access\Domain\Exception\AddressCountryServedByAnotherStore;
use Modules\Access\Domain\Exception\InvalidAddress;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\Platform\Application\Command\AddAddressCountry\AddAddressCountry;
use Modules\Platform\Application\Command\AddAddressCountry\AddAddressCountryHandler;
use Modules\Platform\Application\Command\AddPlace\AddPlace;
use Modules\Platform\Application\Command\AddPlace\AddPlaceHandler;
use Modules\Platform\Application\Command\RetirePlace\RetirePlace;
use Modules\Platform\Application\Command\RetirePlace\RetirePlaceHandler;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

beforeEach(function () {
    FakeBreachList::install();
    seed(PlatformSeeder::class);
});

/*
| Addresses on Platform's places (access.md amendment 76; shipping.md §1.2, §9.3 #1, #4, #10) and the
| owner's answers to step 2 (2026-10-28, all as recommended): the country line printed, a retired
| place kept while left as it was, a typed address incomplete once its country has a list.
|
| Every helper is named after this file's subject: a function in a Pest file is global to the suite.
*/

/**
 * Places made as a Super Admin; the customer acts again afterwards.
 *
 * @return array{region: string, city: string, district: string, other_city: string}
 */
function addressPlacesList(string $customer, string $country = 'SA'): array
{
    Fx::actAsStaff(Fx::staff(superAdmin: true));
    $add = fn (string $kind, ?string $parent, string $ar, string $en): string => app(AddPlaceHandler::class)->handle(new AddPlace($country, $kind, $parent, $ar, $en));

    $region = $add('REGION', null, 'منطقة الرياض', 'Riyadh Region');
    $city = $add('CITY', $region, 'الرياض', 'Riyadh');
    $district = $add('DISTRICT', $city, 'العليا', 'Al Olaya');
    $otherCity = $add('CITY', $add('REGION', null, 'منطقة مكة', 'Makkah Region'), 'جدة', 'Jeddah');

    Fx::actAsCustomer($customer);

    return ['region' => $region, 'city' => $city, 'district' => $district, 'other_city' => $otherCity];
}

/**
 * @param  array<string, mixed>  $with
 */
function addressPlacesSave(array $with = []): string
{
    return app(SaveAddressHandler::class)->handle(new SaveAddress(
        storeId: $with['storeId'] ?? Fx::storeId('sa'),
        label: 'Home',
        recipientName: 'Sara Ali',
        phone: '+966501234567',
        fields: $with['fields'] ?? ['administrative_area' => 'typed', 'city' => 'typed', 'district' => 'typed', 'street' => 'King Fahd Road', 'building' => '7'],
        addressId: $with['addressId'] ?? null,
        countryCode: $with['country'] ?? null,
        regionId: $with['region'] ?? null,
        cityId: $with['city'] ?? null,
        districtId: $with['district'] ?? null,
        language: $with['language'] ?? 'ar',
    ));
}

function addressPlacesRow(string $id): stdClass
{
    return DB::table('access.addresses')->where('id', $id)->first() ?? throw new LogicException("No address {$id}.");
}

describe('the country', function () {
    it('is the store\'s own when none is given, typed where it has no list, and printed last', function () {
        Fx::actAsCustomer(Fx::customer());
        $id = addressPlacesSave(['language' => 'en']);
        $address = app(AccessApi::class)->address($id);

        expect($address?->countryCode)->toBe('SA')
            ->and([$address?->regionId, $address?->cityId, $address?->districtId])->toBe([null, null, null])
            ->and($address?->isComplete)->toBeTrue()
            ->and($address?->formatted)->toEndWith("\nSaudi Arabia")
            ->and(addressPlacesRow($id)->language)->toBe('en');
    });

    it('refuses a country another store ships to, naming that store, an off one too (§9.3 #1)', function () {
        Fx::actAsCustomer(Fx::customer());

        expect(fn () => addressPlacesSave(['country' => 'EG']))->toThrow(AddressCountryServedByAnotherStore::class);

        DB::table('platform.stores')->where('code', 'eg')->update(['is_active' => false]);

        try {
            addressPlacesSave(['country' => 'EG']);
            $this->fail('Saved an address in a country another store ships to.');
        } catch (AddressCountryServedByAnotherStore $e) {
            expect($e->storeCode)->toBe('eg');
        }
    });

    it('refuses a country not on the address list, and takes one no store ships to (OUTSIDE)', function () {
        $customer = Fx::customer();
        Fx::actAsCustomer($customer);

        expect(fn () => addressPlacesSave(['country' => 'QA']))->toThrow(InvalidAddress::class);

        Fx::actAsStaff(Fx::staff(superAdmin: true));
        app(AddAddressCountryHandler::class)->handle(new AddAddressCountry('QA'));
        Fx::actAsCustomer($customer);

        expect(app(AccessApi::class)->address(addressPlacesSave(['country' => 'QA']))?->countryCode)->toBe('QA');
    });

    it('keeps the address\'s own country and places when an edit gives none', function () {
        $customer = Fx::customer();
        Fx::actAsCustomer($customer);
        $places = addressPlacesList($customer);
        $id = addressPlacesSave(['country' => 'SA', 'region' => $places['region'], 'city' => $places['city']]);

        addressPlacesSave(['addressId' => $id]);

        expect(addressPlacesRow($id)->city_id)->toBe($places['city']);
    });
});

describe('a country with a place list', function () {
    it('takes the picked places, keeps their ids, and fills their names in the page\'s language', function () {
        $customer = Fx::customer();
        Fx::actAsCustomer($customer);
        $places = addressPlacesList($customer);

        $id = addressPlacesSave(['country' => 'SA', 'region' => $places['region'], 'city' => $places['city'], 'district' => $places['district'], 'language' => 'en']);
        $address = app(AccessApi::class)->address($id);

        expect([$address?->regionId, $address?->cityId, $address?->districtId])->toBe([$places['region'], $places['city'], $places['district']])
            ->and($address?->fields['administrative_area'] ?? null)->toBe('Riyadh Region')
            ->and($address?->fields['city'] ?? null)->toBe('Riyadh')
            ->and($address?->fields['district'] ?? null)->toBe('Al Olaya');

        $arabic = addressPlacesSave(['country' => 'SA', 'region' => $places['region'], 'city' => $places['city']]);

        expect(app(AccessApi::class)->address($arabic)?->fields['city'] ?? null)->toBe('الرياض');
    });

    it('takes a typed "Other" city and district under a picked region', function () {
        $customer = Fx::customer();
        Fx::actAsCustomer($customer);
        $places = addressPlacesList($customer);

        $id = addressPlacesSave(['country' => 'SA', 'region' => $places['region'], 'fields' => ['administrative_area' => 'x', 'city' => 'Diriyah', 'district' => 'Turaif', 'street' => 'King Fahd Road', 'building' => '7']]);
        $address = app(AccessApi::class)->address($id);

        expect($address?->cityId)->toBeNull()
            ->and($address?->fields['city'] ?? null)->toBe('Diriyah')
            ->and($address?->isComplete)->toBeTrue();
    });

    it('refuses what does not fit the list', function (Closure $with) {
        $customer = Fx::customer();
        Fx::actAsCustomer($customer);
        $places = addressPlacesList($customer);

        expect(fn () => addressPlacesSave($with($places)))->toThrow(InvalidAddress::class);
    })->with([
        'no region' => [fn (array $p) => ['country' => 'SA']],
        'a city as the region' => [fn (array $p) => ['country' => 'SA', 'region' => $p['city']]],
        'a city of another region' => [fn (array $p) => ['country' => 'SA', 'region' => $p['region'], 'city' => $p['other_city']]],
        'a district without its city' => [fn (array $p) => ['country' => 'SA', 'region' => $p['region'], 'district' => $p['district']]],
        'an unknown place' => [fn (array $p) => ['country' => 'SA', 'region' => '01jbz0000000000000000000aa']],
        'not an id' => [fn (array $p) => ['country' => 'SA', 'region' => 'riyadh']],
    ]);

    it('refuses a place in a country with no list', function () {
        $customer = Fx::customer();
        Fx::actAsCustomer($customer);
        $places = addressPlacesList($customer);
        Fx::actAsStaff(Fx::staff(superAdmin: true));
        app(AddAddressCountryHandler::class)->handle(new AddAddressCountry('QA'));
        Fx::actAsCustomer($customer);

        expect(fn () => addressPlacesSave(['country' => 'QA', 'region' => $places['region']]))->toThrow(InvalidAddress::class);
    });

    it('keeps a place retired since while it is left as it was, and refuses picking it anew (Q2)', function () {
        $customer = Fx::customer();
        Fx::actAsCustomer($customer);
        $places = addressPlacesList($customer);
        $id = addressPlacesSave(['country' => 'SA', 'region' => $places['region'], 'city' => $places['city']]);

        Fx::actAsStaff(Fx::staff(superAdmin: true));
        app(RetirePlaceHandler::class)->handle(new RetirePlace($places['city']));
        Fx::actAsCustomer($customer);

        addressPlacesSave(['addressId' => $id, 'country' => 'SA', 'region' => $places['region'], 'city' => $places['city']]);

        expect(addressPlacesRow($id)->city_id)->toBe($places['city'])
            ->and(fn () => addressPlacesSave(['country' => 'SA', 'region' => $places['region'], 'city' => $places['city']]))->toThrow(InvalidAddress::class)
            ->and(fn () => addressPlacesSave(['addressId' => $id, 'country' => 'SA', 'region' => $places['region'], 'city' => $places['city'], 'district' => $places['district']]))->toThrow(InvalidAddress::class);
    });

    it('marks a typed address incomplete once its country has a list (Q3)', function () {
        $customer = Fx::customer();
        Fx::actAsCustomer($customer);
        $id = addressPlacesSave();

        expect(app(AccessApi::class)->address($id)?->isComplete)->toBeTrue();

        addressPlacesList($customer);

        expect(app(AccessApi::class)->address($id)?->isComplete)->toBeFalse();
    });
});

describe('the database, behind the code', function () {
    it('refuses a row the code would never write', function (array $row) {
        Fx::actAsCustomer(Fx::customer());
        $id = addressPlacesSave();

        expect(fn () => DB::table('access.addresses')->where('id', $id)->update($row))->toThrow(QueryException::class);
    })->with([
        'a lower-case country' => [['country_code' => 'sa']],
        'an unknown language' => [['language' => 'fr']],
        'a city with no region' => [['city_id' => '01jbz0000000000000000000ab']],
        'a district with no city' => [['region_id' => '01jbz0000000000000000000ab', 'district_id' => '01jbz0000000000000000000ac']],
    ]);
});
