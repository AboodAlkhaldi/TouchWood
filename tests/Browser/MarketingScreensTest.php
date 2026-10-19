<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Promotions\Support\PromotionsFixtures as Pr;

use function Pest\Laravel\seed;

/*
| Promotions' Segments, Gift Levels and the shop's Coupons tab in a real browser (promotions.md §4.1 M3,
| M4, M5). The feature tests beside these prove what the pages are handed; these prove a person can use
| them: a segment made from its dialog opens its page, the gift levels page draws with its rule line,
| and a customer's coupon card copies its code - at a phone's width with nothing scrolling sideways.
|
| Deliberately no RefreshDatabase, as the other browser tests: every row here has a name of its own,
| from the clock.
*/

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

function marketingScreenTag(): string
{
    return substr((string) hrtime(true), -8);
}

function marketingScreenPanel(): mixed
{
    $email = (string) DB::table('access.staff_users')->where('id', Fx::staff(superAdmin: true))->value('email');

    $page = visit('/admin/sign-in')
        ->type('#email', $email)
        ->type('#password', Fx::STAFF_PASSWORD)
        ->click('button[type="submit"]')
        ->assertPathIs('/admin/sign-in/code')
        ->type('input[autocomplete="one-time-code"]', RecordingSecurityMessages::installed()->lastCode())
        ->click('button[type="submit"]');

    expect(signedInToPanel($page))->toBeTrue();

    return $page;
}

it('makes a rule segment from its dialog and opens its page', function () {
    $name = 'Sleepers '.marketingScreenTag();
    $page = marketingScreenPanel();

    $page->navigate('/admin/segments?store=sa', BROWSER_PAGE_LOAD)
        ->assertSee('Segments')
        ->click('[data-test="new-segment"]')
        ->type('#name_ar', 'نائمون')
        ->type('#name_en', $name)
        ->click('[data-test="rule-NO_ORDER_FOR"]')
        ->type('#rule_months', '6')
        ->click('[data-test="save-segment"]');

    expect(browserUntil($page, "location.pathname.startsWith('/admin/segments/')"))->toBeTrue();
    $page->assertSee($name)->assertSee('No order in 6 months');
});

it('draws Gift Levels and the shop\'s Coupons tab at a phone\'s width with nothing scrolling sideways', function () {
    $page = marketingScreenPanel();
    $page->resize(375, 812);

    foreach (['/admin/segments?store=sa', '/admin/gift-levels?store=sa'] as $uri) {
        $page->navigate($uri, BROWSER_PAGE_LOAD);
        $wide = (string) $page->script('document.documentElement.scrollWidth > document.documentElement.clientWidth ? String(document.documentElement.scrollWidth) : ""');

        expect($wide)->toBe('', "{$uri} scrolls sideways");
    }

    $email = strtolower((string) Str::ulid()).'@example.test';
    $customer = Fx::customer($email);
    $code = 'B-'.marketingScreenTag();
    Pr::assign(Pr::coupon($code, with: ['eligibility' => 'ASSIGNED']), $customer);

    $shop = visit('/sa/en/sign-in')
        ->type('#email', $email)
        ->type('#password', Fx::CUSTOMER_PASSWORD)
        ->click('button[type="submit"]');
    expect(browserUntil($shop, "location.pathname === '/sa/en'"))->toBeTrue();

    $shop->resize(375, 812);
    $shop->navigate('/sa/en/account/coupons', BROWSER_PAGE_LOAD)
        ->assertSee($code)
        ->assertPresent('[data-test="copy-button"]');

    expect((string) $shop->script('document.documentElement.scrollWidth > document.documentElement.clientWidth ? "wide" : ""'))->toBe('');
});
