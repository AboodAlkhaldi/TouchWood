<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\seed;

/*
| E8 and the Stores page's Ships To, in a real browser (platform.md §4.3): a Super Admin opens a
| country, adds a region, follows the trail, retires and restores it, and the
| pages fit a phone.
*/

const PLACES_SCREEN_PASSWORD = 'a long enough password';

// Deliberately no RefreshDatabase: the suite serves the application in this process, and a served
// request opens its own connection, so the two would deadlock.

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

it('adds a region, follows the trail, and fits a phone', function () {
    $email = (string) DB::table('access.staff_users')->where('id', Fx::staff(superAdmin: true))->value('email');

    $page = visit('/admin/sign-in')
        ->type('#email', $email)
        ->type('#password', PLACES_SCREEN_PASSWORD)
        ->click('button[type="submit"]')
        ->assertPathIs('/admin/sign-in/code')
        ->type('input[autocomplete="one-time-code"]', RecordingSecurityMessages::installed()->lastCode())
        ->click('button[type="submit"]');

    expect(signedInToPanel($page))->toBeTrue();
    $page->navigate('/admin/places');

    $page->assertSee('Countries and Places')
        ->assertSeeIn('[data-test="country-SA"] [data-test="open-country"]', 'Saudi Arabia')
        ->assertSeeIn('[data-test="country-SA"] [data-test="ships-from"]', 'Saudi Arabia')
        ->assertNoJavaScriptErrors();

    // The suite's database is not reset between runs: a name of this run's own.
    $number = random_int(1000, 9999);
    $name = "Region {$number}";

    $page->navigate('/admin/places/sa');
    $page->click('[data-test="add-place"]')
        ->type('#place-name-ar', "منطقة {$number}")
        ->type('#place-name-en', $name)
        ->click('[data-test="confirm-place"]');

    $link = "[...document.querySelectorAll('[data-test=\"open-place\"]')].find((link) => link.textContent.includes('{$name}'))";
    expect(browserSoon($page, "{$link} !== undefined"))->toBeTrue();

    $page->script("{$link}.click()");
    expect(browserSoon($page, "location.pathname.split('/').length === 5"))->toBeTrue();
    $page->assertSee('No Cities in This Region Yet')
        // The trail: the countries page, the country, then this region.
        ->assertSeeIn('[data-slot="breadcrumb"]', 'Saudi Arabia')
        ->assertSeeIn('[data-slot="breadcrumb"]', $name)
        ->assertNoJavaScriptErrors();

    // A phone: the trail keeps its last two steps, the rest in its ellipsis menu.
    $page->resize(375, 812);
    expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
    $page->assertVisible('[data-test="trail-more"]');

    // Retired: its row leaves the list (Show retired is off), focus goes to Add, and it comes back.
    $page->resize(1280, 800);
    $page->navigate('/admin/places/sa');
    $id = (string) DB::table('platform.places')->where('country_code', 'SA')->where('name->en', $name)->value('id');
    $row = "(document.querySelector('[data-test=\"place-{$id}\"]') ?? undefined)";
    $page->click("[data-test=\"place-actions-{$id}\"]");
    $page->click('[data-test="retire-place"]')->click('[data-test="confirm-retire-place"]');
    expect(browserSoon($page, "{$row} === undefined"))->toBeTrue()
        ->and(browserSoon($page, "document.activeElement?.dataset.test === 'add-place'"))->toBeTrue();

    $page->click('[data-test="show-retired"]');
    expect(browserSoon($page, "{$row}?.querySelector('[data-test=\"retired\"]') !== null"))->toBeTrue();
    $page->click("[data-test=\"place-actions-{$id}\"]");
    $page->click('[data-test="restore-place"]');
    expect(browserSoon($page, "{$row} !== undefined && {$row}.querySelector('[data-test=\"retired\"]') === null"))->toBeTrue();
    $page->assertNoJavaScriptErrors();

    $page->resize(375, 812);
    $page->navigate('/admin/places');
    expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();

    // The store's card: its own country first, marked Own, and Add Country for a Super Admin.
    $page->navigate('/admin/stores');
    $page->assertSeeIn('[data-test="ship-to-SA"]', 'Own')
        ->assertPresent('[data-test="add-ship-to-sa"]')
        ->assertNoJavaScriptErrors();
    expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();

    // Retired again: nothing rolls this file back, and a Saudi region in use makes Saudi Arabia a
    // country whose places are picked, so the later files' typed Saudi addresses would be refused.
    DB::table('platform.places')->where('id', $id)->update(['retired' => true]);
});
