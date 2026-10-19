<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Application\Command\AddCategory\AddCategory;
use Modules\Catalog\Application\Command\AddCategory\AddCategoryHandler;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\seed;

/*
| Promotions' coupon screens in a real browser (promotions.md §4.1 M1, M2). The feature tests beside
| these prove what the pages are handed; these prove a person can use them: a coupon typed into the
| form, a category picked by its name, saved and opened, turned off, and deleted while unused - and the
| page fits a phone's width.
|
| Deliberately no RefreshDatabase, as the other browser tests: every coupon here has a code of its own,
| from the clock.
*/

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

/** A code of its own, from the clock: this suite keeps its rows. */
function couponScreenCode(): string
{
    return 'B-'.substr((string) hrtime(true), -8);
}

/** A category with a name of its own, from the clock: its slug must be new on every run. */
function couponScreenCategory(): string
{
    $n = substr((string) hrtime(true), -12);

    return Fx::asSystem(fn (): string => app(AddCategoryHandler::class)->handle(new AddCategory("قسم {$n}", "Browser Kitchens {$n}", null)));
}

/** Signed in to the panel as a new Super Admin. */
function couponScreenPanel(): mixed
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

it('creates a coupon through the form with a category picked by name, then turns it off and deletes it', function () {
    $code = couponScreenCode();
    $category = couponScreenCategory();
    $categoryName = (string) DB::table('catalog.categories')->where('id', $category)->value('name_en');
    $page = couponScreenPanel();

    $page->navigate('/admin/coupons/new?store=sa', BROWSER_PAGE_LOAD)
        ->assertSee('New Coupon')
        ->assertSee('Coupons and points together may take at most')
        ->type('#code', $code)
        ->type('#name_ar', 'قسيمة المتصفح')
        ->type('#name_en', 'Browser coupon')
        ->type('#percent', '12')
        ->click('[data-test="add-INCLUDE"]')
        ->type('#filter-category', $categoryName)
        ->click("[data-test=\"pick-category-{$category}\"]")
        ->click('[data-test="picker-done"]')
        ->assertSee($categoryName)
        ->click('[data-test="save-coupon"]');

    expect(browserUntil($page, "location.pathname.startsWith('/admin/coupons/') && !location.pathname.endsWith('/new')"))->toBeTrue();
    $id = (string) DB::table('promotions.coupons')->where('code', $code)->value('id');
    expect($id)->not->toBe('')
        ->and(DB::table('promotions.coupon_rules')->where('coupon_id', $id)->value('category_id'))->toBe($category);

    $page->assertSee($code)->click('[data-test="switch-coupon"]');
    expect(browserUntil($page, "document.querySelector('[data-test=\"switch-coupon\"]')?.textContent.includes('Turn On')"))->toBeTrue()
        ->and(DB::table('promotions.coupons')->where('id', $id)->value('active'))->toBeFalse();

    $page->click('[data-test="delete-coupon"]')
        ->assertSee('Delete Coupon')
        ->type('[data-test="destructive-verification"]', $code)
        ->click('[data-test="destructive-confirm"]');

    expect(browserUntil($page, "location.pathname === '/admin/coupons'"))->toBeTrue()
        ->and(DB::table('promotions.coupons')->where('id', $id)->exists())->toBeFalse();
});

it('draws the list and a coupon\'s form at a phone\'s width with nothing scrolling sideways', function () {
    $page = couponScreenPanel();
    $page->resize(375, 812);

    foreach (['/admin/coupons?store=sa', '/admin/coupons/new?store=sa'] as $uri) {
        $page->navigate($uri, BROWSER_PAGE_LOAD);
        $wide = (string) $page->script('document.documentElement.scrollWidth > document.documentElement.clientWidth ? String(document.documentElement.scrollWidth) : ""');

        expect($wide)->toBe('', "{$uri} scrolls sideways");
    }
});
