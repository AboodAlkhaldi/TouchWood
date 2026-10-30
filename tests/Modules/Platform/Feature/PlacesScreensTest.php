<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Platform\Public\PlatformPermissions;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

beforeEach(function () {
    // The sweep of old sessions runs on 2 requests in 100 (config/session.php) and adds a DELETE: off, so
    // a page's count never depends on a dice roll (AdminFrameQueryBudgetTest).
    config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
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

/**
 * The queries one page read runs, from the request in. Read once first: the session's first page
 * warms what every later page reuses (the menu, the permissions), and that is no page's own.
 */
function placesScreenQueries(Closure $read): int
{
    // One clock for both reads: the staff pass writes its last-seen time once a minute, and a minute
    // turning between them (a slow CI run) would count that write against the page.
    CarbonImmutable::setTestNow(CarbonImmutable::now());

    try {
        $read();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $read();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();
    } finally {
        CarbonImmutable::setTestNow();
    }

    return $count;
}

describe('the pages', function () {
    it('lists the address countries with their stores, and offers only countries not on the list', function () {
        $browser = placesScreenSignIn(Fx::staff(superAdmin: true));
        placesScreenSeed($browser);

        $queries = placesScreenQueries(fn () => $browser->get('/admin/places')->assertOk()->assertInertia(fn (AssertableInertia $inertia) => $inertia
            ->component('Platform/Admin/Places/Index')
            ->has('countries', 3)
            ->where('countries', fn (Collection $rows): bool => $rows->firstWhere('code', 'SA')['store']['code'] === 'sa'
                && $rows->firstWhere('code', 'SA')['hasPlaces'] === true
                && $rows->firstWhere('code', 'SA')['regions'] === 1
                && $rows->firstWhere('code', 'EG')['hasPlaces'] === false)
            // Found by either language's name (Q10): the other one travels with it.
            ->where('addable', fn (Collection $options): bool => $options->whereIn('code', ['SA', 'EG', 'AE'])->isEmpty()
                && $options->firstWhere('code', 'KW')['otherName'] !== $options->firstWhere('code', 'KW')['name'])));

        // Measured 15 on 2026-10-29: the countries with their counts, the stores, the session.
        // Plus one from 2026-10-30 (stage 7 step 4): the Carriers entry's kept count of places no carrier
        // serves, read on every admin page (shipping.md §1.6).
        expect($queries)->toBeLessThanOrEqual(16);
    });

    it('opens a level per page with its trail, and says what retired above it hides', function () {
        $browser = placesScreenSignIn(Fx::staff(superAdmin: true));
        placesScreenSeed($browser);
        [$region, $city] = [placesScreenId('SA', 'Riyadh Region'), placesScreenId('SA', 'Riyadh')];

        $counts = [];
        $counts['regions'] = placesScreenQueries(fn () => $browser->get('/admin/places/sa')->assertOk()->assertInertia(fn (AssertableInertia $inertia) => $inertia
            ->component('Platform/Admin/Places/Level')
            ->where('country.code', 'SA')->where('kind', 'REGION')->where('parents', [])->where('hiddenBy', null)
            ->has('places', 1)->where('places.0.id', $region)->where('places.0.cities', 1)->where('places.0.districts', 1)));
        $counts['cities'] = placesScreenQueries(fn () => $browser->get("/admin/places/sa/{$region}")->assertOk()->assertInertia(fn (AssertableInertia $inertia) => $inertia
            ->where('kind', 'CITY')->has('parents', 1)->where('parents.0.id', $region)->where('places.0.id', $city)->where('places.0.nameEn', 'Riyadh')));
        $counts['districts'] = placesScreenQueries(fn () => $browser->get("/admin/places/sa/{$region}/{$city}")->assertOk()->assertInertia(fn (AssertableInertia $inertia) => $inertia
            ->where('kind', 'DISTRICT')->has('parents', 2)->where('parents.1.id', $city)->where('places.0.nameEn', 'Olaya')));

        $browser->post("/admin/places/sa/places/{$region}/retire")->assertRedirect();
        $browser->get("/admin/places/sa/{$region}/{$city}")->assertInertia(fn (AssertableInertia $inertia) => $inertia->where('hiddenBy', 'REGION'));

        // The city's own mark, once the region is back; and a retired country above all of it.
        $browser->post("/admin/places/sa/places/{$region}/restore")->assertRedirect();
        $browser->post("/admin/places/sa/places/{$city}/retire")->assertRedirect();
        $browser->get("/admin/places/sa/{$region}/{$city}")->assertInertia(fn (AssertableInertia $inertia) => $inertia->where('hiddenBy', 'CITY'));
        $browser->get("/admin/places/sa/{$region}")->assertInertia(fn (AssertableInertia $inertia) => $inertia->where('hiddenBy', null)->where('places.0.retired', true));

        $browser->post('/admin/places', ['country' => 'KW'])->assertRedirect();
        $browser->post('/admin/places/kw/retire')->assertRedirect();
        $browser->get('/admin/places/kw')->assertInertia(fn (AssertableInertia $inertia) => $inertia->where('hiddenBy', 'COUNTRY')->where('country.retired', true));

        // Measured 16, 17 and 17 on 2026-10-29: the trail is one more read.
        // Plus one each from 2026-10-30 (stage 7 step 4): the Carriers entry's kept count (shipping.md §1.6).
        expect($counts['regions'])->toBeLessThanOrEqual(17)
            ->and($counts['cities'])->toBeLessThanOrEqual(18)
            ->and($counts['districts'])->toBeLessThanOrEqual(18);
    });

    it('answers a trail that does not hold together, or an unknown country, with the not-found page', function () {
        $browser = placesScreenSignIn(Fx::staff(superAdmin: true));
        placesScreenSeed($browser);
        [$region, $city] = [placesScreenId('SA', 'Riyadh Region'), placesScreenId('SA', 'Riyadh')];

        $browser->get('/admin/places/eg/'.$region)->assertNotFound();
        $browser->get("/admin/places/sa/{$city}")->assertNotFound();
        $browser->get("/admin/places/sa/{$region}/{$region}")->assertNotFound();
        $browser->get('/admin/places/zz')->assertNotFound();
    });

    it('shows each store\'s ship-to countries, and Add Country\'s choices to a Super Admin only', function () {
        $super = placesScreenSignIn(Fx::staff(superAdmin: true));

        $queries = placesScreenQueries(fn () => $super->get('/admin/stores')->assertOk()->assertInertia(fn (AssertableInertia $inertia) => $inertia
            ->where('stores', fn (Collection $stores): bool => $stores->firstWhere('code', 'sa')['shipsTo'][0]['code'] === 'SA'
                && $stores->firstWhere('code', 'sa')['shipsTo'][0]['own'] === true)
            ->where('shipToChoices', fn (Collection $choices): bool => $choices->firstWhere('code', 'EG')['store']['code'] === 'eg')));

        // A retired country is offered to no store: it comes back on the places page first.
        $super->post('/admin/places', ['country' => 'KW'])->assertRedirect();
        $super->post('/admin/places/kw/retire')->assertRedirect();
        $super->get('/admin/stores')->assertInertia(fn (AssertableInertia $inertia) => $inertia
            ->where('shipToChoices', fn (Collection $choices): bool => ! $choices->contains('code', 'KW')));

        // Measured 16 on 2026-10-29, with the ship-to lists and Add Country's choices.
        // Plus one from 2026-10-30 (stage 7 step 4): the Carriers entry's kept count (shipping.md §1.6).
        expect($queries)->toBeLessThanOrEqual(17);

        placesScreenSignIn(Fx::staffWith([PlatformPermissions::STORE_UPDATE, PlatformPermissions::STORE_VIEW], ['sa'], RoleLevel::Admin))
            ->get('/admin/stores')->assertOk()->assertInertia(fn (AssertableInertia $inertia) => $inertia->where('shipToChoices', null)->has('stores.0.shipsTo', 1));
    });

    it('changes no place through the address of another country', function () {
        $browser = placesScreenSignIn(Fx::staff(superAdmin: true));
        placesScreenSeed($browser);
        $city = placesScreenId('SA', 'Riyadh');

        expect(AdminBrowser::formError($browser->post("/admin/places/eg/places/{$city}/retire")))->not->toBeNull()
            ->and(AdminBrowser::formError($browser->post("/admin/places/eg/places/{$city}", ['name_ar' => 'اسم', 'name_en' => 'Name'])))->not->toBeNull()
            ->and(DB::table('platform.places')->where('id', $city)->value('retired'))->toBeFalse()
            ->and(DB::table('platform.places')->where('id', $city)->value('name->en'))->toBe('Riyadh');
    });

    it('refuses the places pages to an admin', function () {
        $browser = placesScreenSignIn(Fx::staffWith([PlatformPermissions::STORE_UPDATE], ['sa'], RoleLevel::Admin));

        $browser->get('/admin/places')->assertForbidden();
        $browser->get('/admin/places/sa')->assertForbidden();
    });
});
