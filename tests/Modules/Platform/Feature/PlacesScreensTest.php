<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Platform\Public\PlatformPermissions;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

/*
| E8 and the Stores page's Ships To over HTTP (platform.md §4.3; stage 7 step 1's handlers): what each
| button sends, the change made, its toast, and each refusal in its own words. The seeded stores ship
| to their own countries - SA, EG, AE - which are on the address list with no places yet.
|
| Every helper is named after this file's subject: a function in a Pest file is global to the suite.
*/

function placesScreenSignIn(string $staffId): AdminBrowser
{
    $browser = new AdminBrowser('10.9.1.'.random_int(20, 250));

    $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'),
        'password' => Fx::STAFF_PASSWORD,
    ])->assertRedirect('/admin/sign-in/code');

    $browser->post('/admin/sign-in/code', [
        'code' => RecordingSecurityMessages::installed()->lastCode(),
    ])->assertRedirect('/admin');

    return $browser;
}

function placesScreenId(string $country, string $nameEn): string
{
    return (string) (DB::table('platform.places')->where('country_code', $country)->where('name->en', $nameEn)->value('id')
        ?? throw new RuntimeException("No place \"{$nameEn}\"."));
}

/** Riyadh Region, Riyadh in it, Olaya in Riyadh - added through the panel, as a Super Admin does. */
function placesScreenSeed(AdminBrowser $browser): void
{
    $browser->post('/admin/places/sa/places', ['kind' => 'REGION', 'parent' => '', 'name_ar' => 'منطقة الرياض', 'name_en' => 'Riyadh Region'])->assertRedirect();
    $browser->post('/admin/places/sa/places', ['kind' => 'CITY', 'parent' => placesScreenId('SA', 'Riyadh Region'), 'name_ar' => 'الرياض', 'name_en' => 'Riyadh'])->assertRedirect();
    $browser->post('/admin/places/sa/places', ['kind' => 'DISTRICT', 'parent' => placesScreenId('SA', 'Riyadh'), 'name_ar' => 'العليا', 'name_en' => 'Olaya'])->assertRedirect();
}

describe('the places', function () {
    it('adds a region, a city and a district, each under the one above it, with its toast', function () {
        $browser = placesScreenSignIn(Fx::staff(superAdmin: true));
        placesScreenSeed($browser);

        $region = placesScreenId('SA', 'Riyadh Region');
        $city = placesScreenId('SA', 'Riyadh');

        expect(DB::table('platform.places')->where('id', $city)->value('parent_id'))->toBe($region)
            ->and(DB::table('platform.places')->where('id', placesScreenId('SA', 'Olaya'))->value('parent_id'))->toBe($city);

        $done = $browser->post('/admin/places/sa/places', ['kind' => 'CITY', 'parent' => $region, 'name_ar' => 'الدرعية', 'name_en' => 'Diriyah']);
        expect(AdminBrowser::flashed($done, 'status'))->toBe('Place added');
    });

    it('refuses a sibling\'s name in its own words, and keeps what was typed', function () {
        $browser = placesScreenSignIn(Fx::staff(superAdmin: true));
        placesScreenSeed($browser);

        $refused = $browser->post('/admin/places/sa/places', ['kind' => 'CITY', 'parent' => placesScreenId('SA', 'Riyadh Region'), 'name_ar' => 'مدينة أخرى', 'name_en' => 'riyadh']);

        expect(AdminBrowser::formError($refused))->toBe('Couldn\'t use "riyadh": another place here has that name. Choose another name.')
            ->and(AdminBrowser::flashed($refused, '_old_input'))->toBe(['name_ar' => 'مدينة أخرى', 'name_en' => 'riyadh']);
    });

    it('renames, retires and restores a place, and adds nothing under a retired one', function () {
        $browser = placesScreenSignIn(Fx::staff(superAdmin: true));
        placesScreenSeed($browser);
        $city = placesScreenId('SA', 'Riyadh');

        expect(AdminBrowser::flashed($browser->post("/admin/places/sa/places/{$city}", ['name_ar' => 'مدينة الرياض', 'name_en' => 'Riyadh City']), 'status'))->toBe('Names saved')
            ->and(AdminBrowser::flashed($browser->post("/admin/places/sa/places/{$city}/retire"), 'status'))->toBe('Place retired')
            ->and(DB::table('platform.places')->where('id', $city)->value('retired'))->toBeTrue();

        $refused = $browser->post('/admin/places/sa/places', ['kind' => 'DISTRICT', 'parent' => $city, 'name_ar' => 'الملز', 'name_en' => 'Malaz']);
        expect(AdminBrowser::formError($refused))->toBe('Couldn\'t add a place there: the place above it is retired. Restore that place first.');

        expect(AdminBrowser::flashed($browser->post("/admin/places/sa/places/{$city}/restore"), 'status'))->toBe('Place restored')
            ->and(DB::table('platform.places')->where('id', $city)->value('retired'))->toBeFalse();
    });
});

describe('the address countries', function () {
    it('adds a country, retires and restores it; one a store ships to is not retired', function () {
        $browser = placesScreenSignIn(Fx::staff(superAdmin: true));

        expect(AdminBrowser::flashed($browser->post('/admin/places', ['country' => 'kw']), 'status'))->toBe('Country added')
            ->and(DB::table('platform.address_countries')->where('country_code', 'KW')->value('retired'))->toBeFalse()
            ->and(AdminBrowser::flashed($browser->post('/admin/places/kw/retire'), 'status'))->toBe('Country retired')
            ->and(AdminBrowser::flashed($browser->post('/admin/places/kw/restore'), 'status'))->toBe('Country restored');

        $refused = $browser->post('/admin/places/sa/retire');
        expect(AdminBrowser::formError($refused))->toContain('ships there')
            ->and(DB::table('platform.address_countries')->where('country_code', 'SA')->value('retired'))->toBeFalse();
    });
});

describe('a store\'s ship-to countries', function () {
    it('adds a country with its place list, refuses one without or another store\'s, and removes it again', function () {
        $browser = placesScreenSignIn(Fx::staff(superAdmin: true));
        $browser->post('/admin/places', ['country' => 'KW'])->assertRedirect();

        // No city yet: no carrier could be priced there.
        expect(AdminBrowser::formError($browser->post('/admin/stores/sa/countries', ['country' => 'KW'])))->toContain('no cities yet');

        $browser->post('/admin/places/kw/places', ['kind' => 'REGION', 'parent' => '', 'name_ar' => 'العاصمة', 'name_en' => 'Capital'])->assertRedirect();
        $browser->post('/admin/places/kw/places', ['kind' => 'CITY', 'parent' => placesScreenId('KW', 'Capital'), 'name_ar' => 'مدينة الكويت', 'name_en' => 'Kuwait City'])->assertRedirect();

        expect(AdminBrowser::flashed($browser->post('/admin/stores/sa/countries', ['country' => 'kw']), 'status'))->toBe('Country added')
            ->and(AdminBrowser::formError($browser->post('/admin/stores/eg/countries', ['country' => 'KW'])))->toContain('ships there')
            // The store's own country is never taken off its list.
            ->and(AdminBrowser::formError($browser->post('/admin/stores/sa/countries/sa/remove')))->toContain('always ships to its own country')
            ->and(AdminBrowser::flashed($browser->post('/admin/stores/sa/countries/kw/remove'), 'status'))->toBe('Country removed');

        $sa = (string) DB::table('platform.stores')->where('code', 'sa')->value('id');
        expect(DB::table('platform.store_countries')->where('store_id', $sa)->pluck('country_code')->all())->toBe(['SA']);
    });
});

it('refuses every change to an admin, in the panel\'s words: both permissions are reserved', function () {
    $browser = placesScreenSignIn(Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['sa'], RoleLevel::Admin));
    $words = 'Couldn\'t do that: it isn\'t one of your jobs. Ask an administrator for it.';

    expect(AdminBrowser::formError($browser->post('/admin/places', ['country' => 'KW'])))->toBe($words)
        ->and(AdminBrowser::formError($browser->post('/admin/places/sa/places', ['kind' => 'REGION', 'parent' => '', 'name_ar' => 'منطقة', 'name_en' => 'Region'])))->toBe($words)
        ->and(AdminBrowser::formError($browser->post('/admin/stores/sa/countries', ['country' => 'KW'])))->toBe($words)
        ->and(DB::table('platform.address_countries')->where('country_code', 'KW')->exists())->toBeFalse();
});
