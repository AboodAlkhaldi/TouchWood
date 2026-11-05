<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Ops\Application\OpsPermissions;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\seed;

/*
| O8, Reports in a real browser (ops.md §4.15): the menu's Reports section opens the first kind; Show Report puts the
| choices in the address and shows the report, Download beside it; a changed choice hides Download and says so; Revenue's
| sheets as tabs; a kind with no days says it stands as now; a refusal in place; Arabic, Latin digits and a phone.
|
| Deliberately no RefreshDatabase, as the other browser tests. Each run searches its own phrase in `ae`, a few minutes
| before the test's clock, so the Searches report has a row of this run's own.
*/

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

/**
 * @param  list<string>  $permissions
 */
function reportsBrowser(array $permissions, string $locale = 'en'): mixed
{
    $staffId = Fx::staffWith($permissions, ['ae'], RoleLevel::Admin);
    DB::table('access.staff_users')->where('id', $staffId)->update(['locale' => $locale]);

    $page = visit('/admin/sign-in')
        ->type('#email', (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'))
        ->type('#password', Fx::STAFF_PASSWORD)
        ->click('button[type="submit"]');

    expect(browserSoon($page, "window.location.pathname === '/admin/sign-in/code' && document.querySelector('input[autocomplete=\"one-time-code\"]') !== null"))->toBeTrue();

    $page->type('input[autocomplete="one-time-code"]', RecordingSecurityMessages::installed()->lastCode())
        ->click('button[type="submit"]');

    expect(browserSoon($page, "window.location.pathname === '/admin'"))->toBeTrue();

    return $page;
}

/** A search of this run's own in `ae`, a few minutes before the test's clock. */
function reportsBrowserSearch(string $phrase): void
{
    DB::table('catalog.search_log')->insert([
        'store_id' => Fx::storeId('ae'), 'locale' => 'en', 'query' => $phrase, 'results' => 0,
        'searched_at' => CarbonImmutable::now()->subMinutes(5),
    ]);
}

it('opens from the menu, shows a report with its download, and marks changed choices until shown again', function () {
    $run = substr((string) hrtime(true), -10);
    reportsBrowserSearch("oak{$run}");
    $page = reportsBrowser([OpsPermissions::REPORT_VIEW]);

    // The menu's Reports section, after Customers, opens the first kind.
    expect(browserSoon($page, "document.querySelector('a[data-test=\"area-reports\"][href=\"/admin/reports\"]') !== null"))->toBeTrue();
    $page->click('[data-test="area-reports"]');
    expect(browserSoon($page, "window.location.pathname === '/admin/reports/revenue' && document.querySelector('[data-test=\"before\"]') !== null"))->toBeTrue()
        ->and($page->script("document.querySelector('[data-test=\"download\"]') === null"))->toBeTrue()
        ->and((string) $page->script("document.querySelector('[data-test=\"before\"]').textContent"))->toContain('Choose the store and the days, then Show Report.');

    // Searches: Show Report puts the choices in the address and draws this run's row.
    $page->click('[data-test="kind-searches"]');
    expect(browserSoon($page, "window.location.pathname === '/admin/reports/searches' && document.querySelector('[data-test=\"show\"]') !== null"))->toBeTrue();
    $page->click('[data-test="show"]');
    expect(browserSoon($page, "new URLSearchParams(window.location.search).get('store') === 'ae' && document.querySelector('[data-test=\"report\"]') !== null"))->toBeTrue()
        ->and(browserSoon($page, "[...document.querySelectorAll('[data-test=\"row\"]')].some((row) => row.textContent.includes('oak{$run}'))"))->toBeTrue()
        ->and((string) $page->script("document.querySelector('a[data-test=\"download\"]').getAttribute('href')"))->toContain('/admin/reports/searches/download?store='.Fx::storeId('ae'));

    // A reload shows it again, from the address alone.
    $page->navigate((string) $page->script('window.location.pathname + window.location.search'), BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "[...document.querySelectorAll('[data-test=\"row\"]')].some((row) => row.textContent.includes('oak{$run}'))"))->toBeTrue();

    // A changed choice keeps the report, says so, and hides Download until it is shown again.
    $page->click('[data-test="report-days"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"report-days-week\"]') !== null"))->toBeTrue();
    $page->click('[data-test="report-days-week"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"changed\"]') !== null && document.querySelector('[data-test=\"download\"]') === null && document.querySelector('[data-test=\"report\"]') !== null"))->toBeTrue();
    $page->click('[data-test="show"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"changed\"]') === null && document.querySelector('[data-test=\"download\"]') !== null"))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

it('shows Revenue\'s sheets as tabs, says a report of now has no days, and says a refusal in place', function () {
    $page = reportsBrowser([OpsPermissions::REPORT_VIEW]);

    $page->navigate('/admin/reports/revenue', BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"by-month\"]') !== null"))->toBeTrue();
    $page->click('[data-test="by-month"]')->click('[data-test="show"]');
    expect(browserSoon($page, "new URLSearchParams(window.location.search).get('by') === 'month' && document.querySelectorAll('[data-test^=\"sheet-\"][role=\"tab\"]').length === 4"))->toBeTrue();
    $page->click('[data-test="sheet-3"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"sheet-3\"]').getAttribute('data-state') === 'active'"))->toBeTrue();

    // Favourites stand as they are now: no days to choose.
    $page->click('[data-test="kind-favourites"]');
    expect(browserSoon($page, "window.location.pathname === '/admin/reports/favourites' && document.querySelector('[data-test=\"as-now\"]') !== null && document.querySelector('[data-test=\"report-days\"]') === null"))->toBeTrue()
        ->and((string) $page->script("document.querySelector('[data-test=\"before\"]').textContent"))->toContain('Choose the store, then Show Report.');

    // A refusal comes back in words, the choices kept.
    $page->navigate('/admin/reports/revenue?store=ae&from=2026-10-02&to=2026-10-01', BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"refusal\"]')?.textContent.includes('last day') === true"))->toBeTrue()
        ->and($page->script("document.querySelector('[data-test=\"report\"]') === null"))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

it('draws right to left in Arabic, in Latin digits, and fits a phone', function () {
    $run = substr((string) hrtime(true), -10);
    reportsBrowserSearch("بحث{$run}");
    $page = reportsBrowser([OpsPermissions::REPORT_VIEW], 'ar');
    $page->resize(375, 812);

    $page->navigate('/admin/reports/searches?store=ae&from=2026-10-01&to='.CarbonImmutable::now('Asia/Dubai')->format('Y-m-d'), BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.documentElement.dir === 'rtl' && document.querySelector('[data-test=\"report\"]')?.getAttribute('dir') === 'rtl'"))->toBeTrue()
        ->and(browserSoon($page, "[...document.querySelectorAll('[data-test=\"row\"]')].some((row) => row.textContent.includes('{$run}'))"))->toBeTrue()
        ->and((bool) $page->script('/[٠-٩]/.test(document.body.textContent)'))->toBeFalse()
        ->and(browserSoon($page, 'document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});
