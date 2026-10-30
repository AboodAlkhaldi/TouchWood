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
| A carrier's prices in a real browser (shipping.md §4.1 part 2): a Super Admin opens the price dialog, types
| the amount, opens a region in the place picker, ticks a city, saves, sets the carrier's free-shipping amount,
| and the page fits a phone.
|
| Jordan, on the Saudi store's list for this test only, with its own region, city and carrier - set in the
| database and taken away again: the suite's database is not reset between runs, and a Saudi place left in use
| would make every later Saudi address need its places.
*/

const PRICES_SCREEN_PASSWORD = 'a long enough password';

// Deliberately no RefreshDatabase: the suite serves the application in this process, and a served
// request opens its own connection, so the two would deadlock.

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

it('prices a city through the picker, sets free shipping, and fits a phone', function () {
    $email = (string) DB::table('access.staff_users')->where('id', Fx::staff(superAdmin: true))->value('email');
    $store = (string) DB::table('platform.stores')->where('code', 'sa')->value('id');
    $number = random_int(10000, 99999);
    [$region, $city, $carrier] = [strtolower((string) Str::ulid()), strtolower((string) Str::ulid()), strtolower((string) Str::ulid())];
    $added = ! DB::table('platform.address_countries')->where('country_code', 'JO')->exists();
    $shipped = ! DB::table('platform.store_countries')->where('store_id', $store)->where('country_code', 'JO')->exists();

    if ($added) {
        DB::table('platform.address_countries')->insert(['country_code' => 'JO', 'retired' => false, 'created_at' => now(), 'updated_at' => now()]);
    }

    DB::table('platform.places')->insert([
        ['id' => $region, 'country_code' => 'JO', 'kind' => 'REGION', 'parent_id' => null, 'name' => json_encode(['ar' => "منطقة الأسعار {$number}", 'en' => "Prices Region {$number}"]), 'retired' => false, 'created_at' => now(), 'updated_at' => now()],
    ]);
    DB::table('platform.places')->insert([
        ['id' => $city, 'country_code' => 'JO', 'kind' => 'CITY', 'parent_id' => $region, 'name' => json_encode(['ar' => "مدينة الأسعار {$number}", 'en' => "Prices City {$number}"]), 'retired' => false, 'created_at' => now(), 'updated_at' => now()],
    ]);

    if ($shipped) {
        DB::table('platform.store_countries')->insert(['store_id' => $store, 'country_code' => 'JO', 'created_at' => now()]);
    }

    DB::table('shipping.carriers')->insert([
        'id' => $carrier, 'store_id' => $store, 'country_code' => 'JO', 'name_ar' => "ناقل {$number}", 'name_en' => "Prices Carrier {$number}",
        'active' => false, 'position' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);

    try {
        $page = visit('/admin/sign-in')
            ->type('#email', $email)
            ->type('#password', PRICES_SCREEN_PASSWORD)
            ->click('button[type="submit"]')
            ->assertPathIs('/admin/sign-in/code')
            ->type('input[autocomplete="one-time-code"]', RecordingSecurityMessages::installed()->lastCode())
            ->click('button[type="submit"]');

        expect(signedInToPanel($page))->toBeTrue();
        $page->navigate("/admin/shipping/carriers/{$carrier}?store=sa");
        $page->assertVisible('[data-test="prices-empty"]')->assertNoJavaScriptErrors();

        // Add Price: the amount, then the region opened and its city ticked (R3).
        $page->click('[data-test="prices-empty"] [data-test="add-price"]');
        expect(browserSoon($page, "document.querySelector('#price-amount') !== null"))->toBeTrue();
        $page->type('#price-amount', '20');
        expect(browserSoon($page, "document.querySelector('[data-test=\"open-{$region}\"]') !== null"))->toBeTrue();
        $page->click("[data-test=\"open-{$region}\"]");
        expect(browserSoon($page, "document.querySelector('[data-test=\"pick-{$city}\"]') !== null"))->toBeTrue();
        $page->click("[data-test=\"pick-{$city}\"]");
        $page->assertVisible("[data-test=\"place-chip-{$city}\"]");
        $page->click('[data-test="confirm-price"]');

        expect(browserSoon($page, "document.querySelector('#price-amount') === null"))->toBeTrue()
            ->and(browserSoon($page, "document.querySelector('[data-test=\"prices\"] [data-test=\"price-amount\"]')?.textContent.includes('20.00') === true"))->toBeTrue();
        $page->assertVisible("[data-test=\"prices\"] [data-test=\"place-chip-{$city}\"]");

        // The carrier's own free-shipping amount (R11).
        $page->type('#carrier-free-above', '200')->click('[data-test="save-free-above"]');
        expect(browserSoon($page, "document.querySelector('[data-test=\"clear-free-above\"]') !== null"))->toBeTrue()
            ->and((int) DB::table('shipping.carriers')->where('id', $carrier)->value('free_above_minor'))->toBe(20000);

        // A phone: nothing scrolls sideways, the price dialog included.
        $page->resize(375, 812);
        expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
        $page->click('[data-test="add-price"]');
        expect(browserSoon($page, "document.querySelector('#price-amount') !== null"))->toBeTrue()
            ->and($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
        $page->assertNoJavaScriptErrors();
    } finally {
        DB::table('shipping.carriers')->where('id', $carrier)->delete();

        if ($shipped) {
            DB::table('platform.store_countries')->where('store_id', $store)->where('country_code', 'JO')->delete();
        }

        DB::table('platform.places')->where('id', $city)->delete();
        DB::table('platform.places')->where('id', $region)->delete();

        if ($added) {
            DB::table('platform.address_countries')->where('country_code', 'JO')->delete();
        }
    }
});
