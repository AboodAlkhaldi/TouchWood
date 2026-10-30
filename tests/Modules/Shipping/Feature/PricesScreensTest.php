<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Modules\Shipping\Application\ShippingPermissions as P;
use Symfony\Component\HttpFoundation\Response;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\seed;

/*
| The prices screens (shipping.md §4.1 part 2; the owner, 2026-10-30, (a) in fifteen and R6 (c)): a carrier's
| prices and free shipping on its page, the store-wide amount and the places no carrier serves on the
| carriers page, the place picker's levels. Step 4's use cases have their own tests; these are about the
| screens. Every helper is named after this file's subject: a Pest file's functions are global.
*/

uses(RefreshDatabase::class);

// Measured on 2026-10-30, warm, as a Super Admin: 43 for a carrier's page with prices (its own reads: the
// carrier, its prices with their places and those places' regions or cities once, the store-wide amount, the
// country's name) and 43 for the carriers page (adding the places no carrier serves and the store-wide amount).
// The panel's frame is most of it (CarriersScreensTest says what); neither grows with the number of prices.
const PRICES_SCREEN_PAGE_QUERIES = 43;
const PRICES_SCREEN_LIST_QUERIES = 43;

beforeEach(function () {
    // The session sweep's random DELETE stays out of the counts (the backend session's finding, 2026-10-30).
    config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
    Storage::fake('local', ['serve' => true]);
    Storage::fake('public');
    Queue::fake();
});

function pricesScreenSignIn(string $staffId): AdminBrowser
{
    $browser = new AdminBrowser('10.9.3.'.random_int(20, 250));

    $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'),
        'password' => Fx::STAFF_PASSWORD,
    ])->assertRedirect('/admin/sign-in/code');

    $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode()])->assertRedirect('/admin');

    return $browser;
}

/**
 * A Super Admin, Saudi places added through the panel - the place lists skip themselves in tests - and a
 * carrier in the Saudi store: Riyadh Region (Riyadh, Diriyah) and Makkah Region (Jeddah). The carrier's id.
 *
 * @return array{0: AdminBrowser, 1: string}
 */
function pricesScreenCarrier(): array
{
    $browser = pricesScreenSignIn(Fx::staff(superAdmin: true));

    foreach (['Riyadh Region' => ['Riyadh', 'Diriyah'], 'Makkah Region' => ['Jeddah']] as $region => $cities) {
        $browser->post('/admin/places/sa/places', ['kind' => 'REGION', 'parent' => '', 'name_ar' => 'منطقة '.$region, 'name_en' => $region])->assertRedirect();

        foreach ($cities as $city) {
            $browser->post('/admin/places/sa/places', ['kind' => 'CITY', 'parent' => pricesScreenPlace($region), 'name_ar' => 'مدينة '.$city, 'name_en' => $city])->assertRedirect();
        }
    }

    $browser->post('/admin/shipping/carriers', ['store' => 'sa', 'country' => 'SA', 'name_ar' => 'أرامكس', 'name_en' => 'Aramex'])->assertRedirect();

    return [$browser, (string) DB::table('shipping.carriers')->where('name_en', 'Aramex')->value('id')];
}

/** A Saudi place by its English name. */
function pricesScreenPlace(string $nameEn): string
{
    return (string) (DB::table('platform.places')->where('country_code', 'SA')->where('name->en', $nameEn)->value('id')
        ?? throw new RuntimeException("No Saudi place \"{$nameEn}\"."));
}

/** The prices' version the carrier's page read. */
function pricesScreenVersion(AdminBrowser $browser, string $carrier): string
{
    $version = '';
    $browser->get("/admin/shipping/carriers/{$carrier}?store=sa")->assertOk()->assertInertia(function (AssertableInertia $page) use (&$version): void {
        $version = (string) $page->toArray()['props']['pricesVersion'];
    });

    return $version;
}

/**
 * @param  TestResponse<Response>  $response
 * @return array<string, mixed>
 */
function pricesScreenErrors(TestResponse $response): array
{
    $errors = AdminBrowser::flashed($response, 'errors');

    if ($errors instanceof ViewErrorBag) {
        return $errors->getBag('default')->toArray();
    }

    return is_array($errors) ? ($errors['default']['messages'] ?? []) : [];
}

function pricesScreenQueries(AdminBrowser $browser, string $uri): int
{
    CarbonImmutable::setTestNow(CarbonImmutable::now());

    try {
        $browser->get($uri)->assertOk();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $browser->get($uri)->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();
    } finally {
        CarbonImmutable::setTestNow();
    }

    return $count;
}

describe('a carrier\'s prices (C3, R1-R10)', function () {
    it('starts with none, offered to change to a price manager only (R14)', function () {
        [$browser, $carrier] = pricesScreenCarrier();

        $browser->get("/admin/shipping/carriers/{$carrier}?store=sa")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('prices', [])->where('mayManagePrices', true)->where('decimals', 2)
            ->where('pricesVersion', fn (string $version): bool => $version !== ''));

        pricesScreenSignIn(Fx::staffWith([P::CARRIERS_VIEW], ['sa']))->get("/admin/shipping/carriers/{$carrier}?store=sa")
            ->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('mayManagePrices', false));
    });

    it('saves a price for a whole region, typed in the store\'s currency, and shows it by name', function () {
        [$browser, $carrier] = pricesScreenCarrier();
        $region = pricesScreenPlace('Riyadh Region');

        $response = $browser->post("/admin/shipping/carriers/{$carrier}/prices", ['store' => 'sa', 'amount' => '٢٠.٥٠', 'version' => pricesScreenVersion($browser, $carrier), 'places' => [$region]]);

        expect(AdminBrowser::flashed($response, 'status'))->toBe('Price saved.');
        $browser->get("/admin/shipping/carriers/{$carrier}?store=sa")->assertInertia(fn (AssertableInertia $page) => $page
            ->has('prices', 1)
            ->where('prices.0.amount.minor', 2050)
            ->where('prices.0.places.0.id', $region)->where('prices.0.places.0.kind', 'REGION')->where('prices.0.places.0.under', null));
    });

    it('says a price box\'s refusal beside it, and a stale version with Reload (R10)', function () {
        [$browser, $carrier] = pricesScreenCarrier();
        $region = pricesScreenPlace('Riyadh Region');
        $version = pricesScreenVersion($browser, $carrier);

        expect(pricesScreenErrors($browser->post("/admin/shipping/carriers/{$carrier}/prices", ['store' => 'sa', 'amount' => '20.555', 'version' => $version, 'places' => [$region]])))->toHaveKey('amount');

        $browser->post("/admin/shipping/carriers/{$carrier}/prices", ['store' => 'sa', 'amount' => '20', 'version' => $version, 'places' => [$region]])->assertRedirect();
        // The same version again: another save came first. Said once, as `stale` - the dialog's Reload - and
        // refused every time it is sent again with the version it was opened on.
        foreach ([1, 2] as $try) {
            $refused = pricesScreenErrors($browser->post("/admin/shipping/carriers/{$carrier}/prices", ['store' => 'sa', 'amount' => '30', 'version' => $version, 'places' => [pricesScreenPlace('Jeddah')]]));
            expect($refused)->toHaveKey('stale')->and(array_key_exists('form', $refused))->toBeFalse();
        }

        expect(DB::table('shipping.price_groups')->count())->toBe(1);
    });

    it('refuses a delete sent with a version another change came after', function () {
        [$browser, $carrier] = pricesScreenCarrier();
        $old = pricesScreenVersion($browser, $carrier);
        $browser->post("/admin/shipping/carriers/{$carrier}/prices", ['store' => 'sa', 'amount' => '20', 'version' => $old, 'places' => [pricesScreenPlace('Riyadh Region')]])->assertRedirect();
        $group = (string) DB::table('shipping.price_groups')->value('id');
        $seen = pricesScreenVersion($browser, $carrier);
        $browser->post("/admin/shipping/carriers/{$carrier}/prices", ['store' => 'sa', 'amount' => '30', 'version' => $seen, 'places' => [pricesScreenPlace('Jeddah')]])->assertRedirect();

        expect(pricesScreenErrors($browser->post("/admin/shipping/carriers/{$carrier}/prices/{$group}/delete", ['store' => 'sa', 'version' => $seen])))->toHaveKey('stale')
            ->and(DB::table('shipping.price_groups')->count())->toBe(2);
    });

    it('prices every place not yet priced with Select all (R6 (a)), leaving the others where they are', function () {
        [$browser, $carrier] = pricesScreenCarrier();
        $browser->post("/admin/shipping/carriers/{$carrier}/prices", ['store' => 'sa', 'amount' => '20', 'version' => pricesScreenVersion($browser, $carrier), 'places' => [pricesScreenPlace('Riyadh')]])->assertRedirect();

        $browser->post("/admin/shipping/carriers/{$carrier}/prices", ['store' => 'sa', 'amount' => '30', 'version' => pricesScreenVersion($browser, $carrier), 'places' => [], 'all_unpriced' => true])->assertRedirect();

        $thirty = (string) DB::table('shipping.price_groups')->where('amount_minor', 3000)->value('id');
        expect(DB::table('shipping.price_places')->where('group_id', $thirty)->pluck('place_id')->sort()->values()->all())
            ->toBe(collect([pricesScreenPlace('Diriyah'), pricesScreenPlace('Jeddah')])->sort()->values()->all())
            ->and(DB::table('shipping.price_groups')->where('amount_minor', 2000)->exists())->toBeTrue();
    });

    it('finds no carrier of another store: its prices and free shipping are refused there', function () {
        [$browser, $carrier] = pricesScreenCarrier();
        $version = pricesScreenVersion($browser, $carrier);

        expect(AdminBrowser::formError($browser->post("/admin/shipping/carriers/{$carrier}/prices", ['store' => 'eg', 'amount' => '20', 'version' => $version, 'places' => [pricesScreenPlace('Riyadh Region')]])))->not->toBeNull()
            ->and(AdminBrowser::formError($browser->post("/admin/shipping/carriers/{$carrier}/free-above", ['store' => 'eg', 'free_above' => '200'])))->not->toBeNull()
            ->and(AdminBrowser::formError($browser->post("/admin/shipping/carriers/{$carrier}/free-above", ['free_above' => '200'])))->not->toBeNull()
            ->and(DB::table('shipping.price_groups')->count())->toBe(0)
            ->and(DB::table('shipping.carriers')->where('id', $carrier)->value('free_above_minor'))->toBeNull();
    });

    it('points a new price with an amount the carrier has to that price', function () {
        [$browser, $carrier] = pricesScreenCarrier();
        $region = pricesScreenPlace('Riyadh Region');
        $browser->post("/admin/shipping/carriers/{$carrier}/prices", ['store' => 'sa', 'amount' => '20', 'version' => pricesScreenVersion($browser, $carrier), 'places' => [$region]])->assertRedirect();
        $group = (string) DB::table('shipping.price_groups')->value('id');

        $refused = pricesScreenErrors($browser->post("/admin/shipping/carriers/{$carrier}/prices", ['store' => 'sa', 'amount' => '20.00', 'version' => pricesScreenVersion($browser, $carrier), 'places' => [pricesScreenPlace('Jeddah')]]));

        expect($refused)->toHaveKeys(['form', 'existing'])->and($refused['existing'])->toBe([$group]);
    });

    it('moves a place from another price only when asked (R5), and everything at once (R6 (c))', function () {
        [$browser, $carrier] = pricesScreenCarrier();
        $region = pricesScreenPlace('Riyadh Region');
        $city = pricesScreenPlace('Riyadh');
        $browser->post("/admin/shipping/carriers/{$carrier}/prices", ['store' => 'sa', 'amount' => '20', 'version' => pricesScreenVersion($browser, $carrier), 'places' => [$city]])->assertRedirect();

        // Not asked to move: refused, in its own words.
        expect(AdminBrowser::formError($browser->post("/admin/shipping/carriers/{$carrier}/prices", ['store' => 'sa', 'amount' => '30', 'version' => pricesScreenVersion($browser, $carrier), 'places' => [$city]])))->not->toBeNull();

        // Everything moved here: every region and the other price's places; the other price, emptied, goes.
        $regions = DB::table('platform.places')->where('country_code', 'SA')->where('kind', 'REGION')->where('retired', false)->pluck('id')->all();
        $browser->post("/admin/shipping/carriers/{$carrier}/prices", ['store' => 'sa', 'amount' => '30', 'version' => pricesScreenVersion($browser, $carrier), 'places' => [...$regions, $city], 'moves' => [$city]])->assertRedirect();

        expect(DB::table('shipping.price_groups')->pluck('amount_minor')->all())->toBe([3000]);
    });

    it('deletes a price', function () {
        [$browser, $carrier] = pricesScreenCarrier();
        $browser->post("/admin/shipping/carriers/{$carrier}/prices", ['store' => 'sa', 'amount' => '20', 'version' => pricesScreenVersion($browser, $carrier), 'places' => [pricesScreenPlace('Riyadh Region')]])->assertRedirect();
        $group = (string) DB::table('shipping.price_groups')->value('id');

        $response = $browser->post("/admin/shipping/carriers/{$carrier}/prices/{$group}/delete", ['store' => 'sa', 'version' => pricesScreenVersion($browser, $carrier)]);

        expect(AdminBrowser::flashed($response, 'status'))->toBe('Price deleted.')
            ->and(DB::table('shipping.price_groups')->count())->toBe(0);
    });

    it('refuses a viewer\'s save, the handler asking again', function () {
        [$browser, $carrier] = pricesScreenCarrier();
        $viewer = pricesScreenSignIn(Fx::staffWith([P::CARRIERS_VIEW], ['sa']));

        expect(AdminBrowser::formError($viewer->post("/admin/shipping/carriers/{$carrier}/prices", ['store' => 'sa', 'amount' => '20', 'version' => pricesScreenVersion($browser, $carrier), 'places' => [pricesScreenPlace('Riyadh Region')]])))->not->toBeNull()
            ->and(DB::table('shipping.price_groups')->count())->toBe(0);
    });

    it('reads the page in a bounded number of queries, not one more per price', function () {
        [$browser, $carrier] = pricesScreenCarrier();
        // A region and a city: the cities' regions are read once, for every price together.
        $browser->post("/admin/shipping/carriers/{$carrier}/prices", ['store' => 'sa', 'amount' => '20', 'version' => pricesScreenVersion($browser, $carrier), 'places' => [pricesScreenPlace('Riyadh Region'), pricesScreenPlace('Jeddah')]])->assertRedirect();
        $one = pricesScreenQueries($browser, "/admin/shipping/carriers/{$carrier}?store=sa");
        $browser->post("/admin/shipping/carriers/{$carrier}/prices", ['store' => 'sa', 'amount' => '30', 'version' => pricesScreenVersion($browser, $carrier), 'places' => [pricesScreenPlace('Diriyah')]])->assertRedirect();

        expect(pricesScreenQueries($browser, "/admin/shipping/carriers/{$carrier}?store=sa"))->toBe($one)
            ->and($one)->toBeLessThanOrEqual(PRICES_SCREEN_PAGE_QUERIES);
    });
});

describe('the place picker\'s levels (R3)', function () {
    it('lists the carrier\'s country\'s regions, a region\'s cities, a city\'s districts', function () {
        [$browser, $carrier] = pricesScreenCarrier();
        $region = pricesScreenPlace('Riyadh Region');

        $regions = $browser->get("/admin/shipping/carriers/{$carrier}/places?store=sa")->assertOk()->collect();
        $cities = $browser->get("/admin/shipping/carriers/{$carrier}/places?store=sa&region={$region}")->assertOk()->collect();

        expect($regions->pluck('id'))->toContain($region)
            ->and($regions->first())->toHaveKeys(['id', 'name', 'otherName'])
            ->and($cities->pluck('id'))->toContain(pricesScreenPlace('Riyadh'));
    });

    it('is the carrier\'s own: another store\'s address finds it not', function () {
        [$browser, $carrier] = pricesScreenCarrier();

        $browser->get("/admin/shipping/carriers/{$carrier}/places?store=eg")->assertNotFound();
    });
});

describe('free shipping (R11, R12)', function () {
    it('saves and clears the carrier\'s own amount, and says a wrong one beside its box', function () {
        [$browser, $carrier] = pricesScreenCarrier();

        expect(AdminBrowser::flashed($browser->post("/admin/shipping/carriers/{$carrier}/free-above", ['store' => 'sa', 'free_above' => '200']), 'status'))->toBe('Free shipping amount saved.')
            ->and(DB::table('shipping.carriers')->where('id', $carrier)->value('free_above_minor'))->toBe(20000)
            ->and(pricesScreenErrors($browser->post("/admin/shipping/carriers/{$carrier}/free-above", ['store' => 'sa', 'free_above' => 'abc'])))->toHaveKey('free_above');

        $browser->post("/admin/shipping/carriers/{$carrier}/free-above", ['store' => 'sa', 'clear' => true])->assertRedirect();
        expect(DB::table('shipping.carriers')->where('id', $carrier)->value('free_above_minor'))->toBeNull();

        // Whoever may only view is refused, the handler asking again (R14).
        $viewer = pricesScreenSignIn(Fx::staffWith([P::CARRIERS_VIEW], ['sa']));
        expect(AdminBrowser::formError($viewer->post("/admin/shipping/carriers/{$carrier}/free-above", ['store' => 'sa', 'free_above' => '200'])))->not->toBeNull()
            ->and(DB::table('shipping.carriers')->where('id', $carrier)->value('free_above_minor'))->toBeNull();
    });

    it('shows the store-wide amount, saved through Platform\'s settings route in the smallest unit', function () {
        [$browser] = pricesScreenCarrier();

        $browser->get('/admin/shipping/carriers?store=sa')->assertInertia(fn (AssertableInertia $page) => $page->where('storeFreeAbove', null)->where('mayManagePrices', true));
        $browser->post('/admin/settings/shipping.free.above_all', ['store' => 'sa', 'value' => '50000'])->assertRedirect();

        $browser->get('/admin/shipping/carriers?store=sa')->assertInertia(fn (AssertableInertia $page) => $page->where('storeFreeAbove.minor', 50000));
    });
});

describe('places no carrier serves (R13)', function () {
    it('lists the biggest places nothing serves, per country, and fewer once a carrier prices a region', function () {
        [$browser, $carrier] = pricesScreenCarrier();
        $before = 0;
        $browser->get('/admin/shipping/carriers?store=sa')->assertInertia(function (AssertableInertia $page) use (&$before): void {
            $before = count($page->toArray()['props']['countries'][0]['uncovered']);
        });

        $browser->post("/admin/shipping/carriers/{$carrier}/prices", ['store' => 'sa', 'amount' => '20', 'version' => pricesScreenVersion($browser, $carrier), 'places' => [pricesScreenPlace('Riyadh Region')]])->assertRedirect();

        expect($before)->toBeGreaterThan(0);
        $browser->get('/admin/shipping/carriers?store=sa')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('countries.0.code', 'SA')
            ->where('countries.0.uncovered', fn ($places): bool => count($places) === $before - 1));

        $list = pricesScreenQueries($browser, '/admin/shipping/carriers?store=sa');
        expect($list)->toBeLessThanOrEqual(PRICES_SCREEN_LIST_QUERIES);
    });
});
