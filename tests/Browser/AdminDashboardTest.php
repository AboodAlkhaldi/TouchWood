<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Sales\Support\CheckoutFixtures as Cx;

use function Pest\Laravel\seed;

/*
| The admin dashboard in a real browser (dashboard.md §2, §4, §7): the greeting row, the period's sections, the
| charts arriving just after the page shows (their own import), All Stores' store tabs, the period kept in the
| address, Refresh, a phone's one column with no sideways scroll, and Latin digits on an Arabic page.
|
| No RefreshDatabase - the suite keeps its data - so the figures are whatever the stores hold; the test places an
| order of its own so the charts have a day to draw, and asserts the shape, never a total.
*/

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
    Storage::fake('local', ['serve' => true]);
    Queue::fake();
});

function adminDashboardBrowserSignIn(string $staffId): mixed
{
    $email = (string) DB::table('access.staff_users')->where('id', $staffId)->value('email');

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

it('shows the dashboard\'s sections, loads its charts after the page, and keeps the store and period in the address', function () {
    Fx::asSystem(static function (): void {
        $ready = Cx::ready();
        Cx::place(Cx::start($ready['address'])->id);
    });
    $page = adminDashboardBrowserSignIn(Fx::staff(superAdmin: true, firstName: 'Sara'));

    // The greeting row: the greeting, the store switcher on All Stores, the period on This Month, Today's Report.
    $page->assertPresent('[data-test="home-greeting-row"] [data-test="store-filter"]')
        ->assertPresent('[data-test="home-greeting-row"] [data-test="period-filter"]')
        ->assertSeeIn('[data-test="home-greeting-row"] [data-test="home-action"]', "Today's Report (PDF)")
        ->assertSee('Sara')
        ->assertSeeIn('[data-test="section-sales.sales"]', 'Sales')
        ->assertSeeIn('[data-test="section-sales.orders_now"]', 'On the Way')
        ->assertSeeIn('[data-test="section-sales.recent_orders"]', 'View All Orders')
        ->assertPresent('[data-test="home-updated"] [data-test="home-refresh"]')
        ->assertNoJavaScriptErrors();
    expect($page->script("document.querySelector('[data-test=\"period-filter\"]').value"))->toBe('month');

    // The charts arrive by their own import once the page shows: every place drawn, none left waiting.
    expect(browserSoon($page, "document.querySelector('[data-test=\"home-series\"] .recharts-surface') !== null"))->toBeTrue('the line chart never arrived')
        ->and(browserSoon($page, "document.querySelectorAll('[data-test=\"chart-waiting\"]').length === 0"))->toBeTrue('a chart\'s place is still waiting')
        // Its figures in words for a screen reader, beside the drawing.
        ->and($page->script("document.querySelectorAll('[data-test=\"home-series\"] .sr-only table tbody tr').length > 0"))->toBeTrue();

    // All Stores: the charts one store at a time, behind store tabs, the base store first.
    $page->assertPresent('[data-test="section-sales.by_day"] [data-test="store-tab-sa"]');
    $page->click('[data-test="section-sales.by_day"] [data-test="store-tab-eg"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"section-sales.by_day\"] [data-test=\"panel-eg\"]')?.offsetParent !== null"))->toBeTrue();

    // The period, then a store: both kept in the address.
    $page->select('[data-test="period-filter"]', 'year');
    expect(browserSoon($page, "new URLSearchParams(location.search).get('period') === 'year'"))->toBeTrue();
    $page->select('[data-test="store-filter"]', 'sa');
    expect(browserSoon($page, "new URLSearchParams(location.search).get('store') === 'sa' && new URLSearchParams(location.search).get('period') === 'year'"))->toBeTrue()
        ->and(browserSoon($page, "document.querySelector('[data-test=\"section-sales.by_day\"] [data-test=\"store-tab-sa\"]') === null"))->toBeTrue('one store needs no tabs');

    // Refresh reads the figures again, on the same store and period.
    $page->click('[data-test="home-refresh"]');
    expect(browserSoon($page, "location.pathname === '/admin' && new URLSearchParams(location.search).get('store') === 'sa' && new URLSearchParams(location.search).get('period') === 'year'"))->toBeTrue();
    $page->assertNoJavaScriptErrors();

    // A phone, left to right: nothing pushes the page sideways - the screen readers' tables of the charts included,
    // which once did (a table grows to its cells whatever width sr-only gives it).
    $page->resize(375, 812);
    expect(browserSoon($page, "document.querySelector('[data-test=\"home-series\"] .recharts-surface') !== null && document.documentElement.scrollWidth <= window.innerWidth"))->toBeTrue('the dashboard scrolls sideways on a phone');
    $page->resize(1280, 800);
});

it('lays the dashboard out in one column on a phone, with no sideways scroll, and writes Latin digits on an Arabic page', function () {
    $staff = Fx::staff(superAdmin: true);
    DB::table('access.staff_users')->where('id', $staff)->update(['locale' => 'ar']);
    $page = adminDashboardBrowserSignIn($staff);

    expect(browserSoon($page, "document.documentElement.dir === 'rtl'"))->toBeTrue()
        ->and(browserSoon($page, "document.querySelector('[data-test=\"home-series\"] .recharts-surface') !== null"))->toBeTrue();

    $page->resize(375, 812);
    expect(browserSoon($page, 'document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue('the dashboard scrolls sideways on a phone')
        // One column: the first two figure cards stacked, not side by side.
        ->and(browserSoon($page, "(() => { const a = document.querySelector('[data-test=\"section-sales.sales\"]').getBoundingClientRect(); const b = document.querySelector('[data-test=\"section-sales.orders\"]').getBoundingClientRect(); return b.top >= a.bottom; })()"))->toBeTrue()
        ->and($page->script("/[\\u0660-\\u0669\\u06F0-\\u06F9]/.test(document.querySelector('[data-test=\"home-sections\"]').innerText)"))->toBeFalse();
    $page->resize(1280, 800);
    $page->assertNoJavaScriptErrors();
});
