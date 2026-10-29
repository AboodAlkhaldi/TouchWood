<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\seed;

/*
| C1-C3 in a real browser (shipping.md §4.1 part 1): a Super Admin adds a carrier with a tracking link and
| sees what a number opens, finds Delete out of reach while it is on, switches it off through the question,
| deletes it, and the page fits a phone.
*/

const CARRIERS_SCREEN_PASSWORD = 'a long enough password';

// Deliberately no RefreshDatabase: the suite serves the application in this process, and a served
// request opens its own connection, so the two would deadlock.

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

it('adds a carrier, switches it off through the question, deletes it, and fits a phone', function () {
    $email = (string) DB::table('access.staff_users')->where('id', Fx::staff(superAdmin: true))->value('email');

    $page = visit('/admin/sign-in')
        ->type('#email', $email)
        ->type('#password', CARRIERS_SCREEN_PASSWORD)
        ->click('button[type="submit"]')
        ->assertPathIs('/admin/sign-in/code')
        ->type('input[autocomplete="one-time-code"]', RecordingSecurityMessages::installed()->lastCode())
        ->click('button[type="submit"]');

    expect(signedInToPanel($page))->toBeTrue();
    $page->navigate('/admin/shipping/carriers?store=sa');
    $page->assertSee('Carriers')
        ->assertSeeIn('#carriers-SA-title', 'Saudi Arabia')
        ->assertSeeIn('[data-test="carriers-SA"] [data-test="country-own"]', 'Own')
        ->assertNoJavaScriptErrors();

    // The suite's database is not reset between runs: a name of this run's own.
    $name = 'Carrier '.random_int(10000, 99999);

    $page->click('[data-test="carriers-SA"] [data-test="add-carrier-SA"]');
    expect(browserSoon($page, "document.querySelector('#carrier-name-en') !== null"))->toBeTrue();
    $page->type('#carrier-name-ar', 'ناقل '.$name)
        ->type('#carrier-name-en', $name)
        ->type('#carrier-tracking', 'https://track.example/{number}');
    // What a tracking number opens, shown as it is typed (Q8).
    $page->assertSeeIn('[data-test="carrier-tracking-example"]', 'https://track.example/12345');
    $page->click('[data-test="confirm-carrier"]');

    expect(browserSoon($page, "document.querySelector('#carrier-name-en') === null"))->toBeTrue();
    $id = (string) DB::table('shipping.carriers')->where('name_en', $name)->value('id');
    $row = "[data-test=\"carrier-{$id}\"]";
    expect($id)->not->toBe('')
        ->and(browserSoon($page, "document.querySelector('{$row}') !== null"))->toBeTrue();
    $page->assertSeeIn("{$row} [data-test=\"carrier-name\"]", $name)->assertSeeIn($row, 'Tracking link');

    // Delete is out of reach while it is on, with the reason (Q6).
    $page->click("[data-test=\"carrier-actions-{$id}\"]");
    expect(browserSoon($page, "document.querySelector('[data-test=\"delete-carrier\"]')?.getAttribute('aria-disabled') === 'true'"))->toBeTrue();
    $page->assertSeeIn('[data-test="delete-carrier"]', 'Switch it off first');
    $page->keys('[data-test="delete-carrier"]', 'Escape');

    // Switching off asks first (Q5).
    $page->click("[data-test=\"carrier-switch-{$id}\"]");
    $page->assertSee('It leaves every new checkout at once.');
    $page->click('[data-test="confirm-carrier-off"]');
    expect(browserSoon($page, "document.querySelector('{$row} [data-test=\"carrier-off\"]') !== null"))->toBeTrue();

    // A phone: a card each, its switch on it, and nothing scrolls sideways - nor on its own page.
    $page->resize(375, 812);
    expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
    $page->assertVisible("[data-test=\"carrier-card-{$id}\"]")->assertVisible("[data-test=\"carrier-card-switch-{$id}\"]");
    $page->navigate("/admin/shipping/carriers/{$id}?store=sa");
    expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
    $page->navigate('/admin/shipping/carriers?store=sa');
    $page->resize(1280, 800);

    // Off, it is deleted through its dialog.
    $page->click("[data-test=\"carrier-actions-{$id}\"]");
    // Geist's Destructive Action Modal: the carrier's name typed to confirm (C1 "Delete").
    $page->click('[data-test="delete-carrier"]')
        ->type('[data-test="destructive-verification"]', $name)
        ->click('[data-test="destructive-confirm"]');
    expect(browserSoon($page, "document.querySelector('{$row}') === null"))->toBeTrue()
        ->and(DB::table('shipping.carriers')->where('id', $id)->exists())->toBeFalse();
    $page->assertNoJavaScriptErrors();
});

it('links a refused ship-to removal to the carriers that hold the country (Q14)', function () {
    $email = (string) DB::table('access.staff_users')->where('id', Fx::staff(superAdmin: true))->value('email');
    $store = (string) DB::table('platform.stores')->where('code', 'sa')->value('id');
    // Jordan on the Saudi store's list with a carrier there - set in the database: the suite's database is
    // not reset between runs, so what this test adds it takes away again.
    $added = ! DB::table('platform.address_countries')->where('country_code', 'JO')->exists();
    $carrier = strtolower((string) Str::ulid());

    if ($added) {
        DB::table('platform.address_countries')->insert(['country_code' => 'JO', 'retired' => false, 'created_at' => now(), 'updated_at' => now()]);
    }

    DB::table('platform.store_countries')->insertOrIgnore(['store_id' => $store, 'country_code' => 'JO', 'created_at' => now()]);
    DB::table('shipping.carriers')->insert([
        'id' => $carrier, 'store_id' => $store, 'country_code' => 'JO', 'name_ar' => 'ناقل الأردن', 'name_en' => 'Jordan Carrier',
        'active' => false, 'position' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);

    try {
        $page = visit('/admin/sign-in')
            ->type('#email', $email)
            ->type('#password', CARRIERS_SCREEN_PASSWORD)
            ->click('button[type="submit"]')
            ->assertPathIs('/admin/sign-in/code')
            ->type('input[autocomplete="one-time-code"]', RecordingSecurityMessages::installed()->lastCode())
            ->click('button[type="submit"]');

        expect(signedInToPanel($page))->toBeTrue();
        $page->navigate('/admin/stores');
        $page->click('[data-test="remove-ship-to-JO"]')->click('[data-test="confirm-remove-ship-to"]');

        expect(browserSoon($page, "document.querySelector('[data-test=\"open-carriers\"]') !== null"))->toBeTrue();
        $page->assertSeeIn('[data-test="open-carriers"]', 'Open the carriers of')
            ->click('[data-test="open-carriers"]');
        expect(browserSoon($page, "location.pathname === '/admin/shipping/carriers' && location.search === '?store=sa'"))->toBeTrue();
        $page->assertSeeIn('[data-test="carriers-JO"] [data-test="carrier-name"]', 'Jordan Carrier')->assertNoJavaScriptErrors();
    } finally {
        DB::table('shipping.carriers')->where('id', $carrier)->delete();
        DB::table('platform.store_countries')->where('store_id', $store)->where('country_code', 'JO')->delete();

        if ($added) {
            DB::table('platform.address_countries')->where('country_code', 'JO')->delete();
        }
    }
});
