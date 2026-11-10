<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Ops\Application\OpsPermissions;
use Modules\Sales\Public\Contracts\SalesReports;
use Modules\Sales\Public\Dto\ProductSaleDto;
use Modules\Sales\Public\Dto\ProductSalesPageDto;
use Modules\Sales\Public\Dto\ProductSalesTotalsDto;
use Shared\Domain\ValueObject\Money;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\seed;

/*
| O8, Reports in a real browser (ops.md §4.15): the menu's Reports section opens the first kind; Show Report puts the
| choices in the address and shows the report, Download beside it; a changed choice hides Download and says so; Revenue's
| sheets as tabs; a kind with no days says it stands as now; a refusal in place; an empty sheet with no total; a cell with
| no value as "—", a count grouped in Latin digits, the 50-rows line and the report-language line; Arabic and a phone.
|
| Deliberately no RefreshDatabase, as the other browser tests. Browser tests run on the real clock (TestCase), so every
| day here is read from today in `ae`'s own zone, never written out. Each run searches its own phrase in `ae` at the start
| of today there, inside every range the tests show.
*/

const REPORTS_BROWSER_ZONE = 'Asia/Dubai';

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

/** Today in `ae`'s own time, as the reports read its days. */
function reportsBrowserToday(): CarbonImmutable
{
    return CarbonImmutable::now(REPORTS_BROWSER_ZONE);
}

/** A search of this run's own in `ae`, at the start of today there: inside every range shown, never after now. */
function reportsBrowserSearch(string $phrase): void
{
    DB::table('catalog.search_log')->insert([
        'store_id' => Fx::storeId('ae'), 'locale' => 'en', 'query' => $phrase, 'results' => 0,
        'searched_at' => reportsBrowserToday()->startOfDay()->format('Y-m-d H:i:sP'),
    ]);
}

/** The page fits a phone once it has settled (§4.15: every browser test checks this). */
function reportsBrowserFitsPhone(mixed $page): bool
{
    $page->resize(375, 812);

    return browserSoon($page, 'document.documentElement.scrollWidth <= window.innerWidth');
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

    // Each kind is a link to its own address.
    expect((string) $page->script("document.querySelector('a[data-test=\"kind-searches\"]')?.getAttribute('href') ?? ''"))->toBe('/admin/reports/searches');

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

    // A changed choice keeps the report, says so, and hides Download until it is shown again. By Month can never equal
    // the opening By Day, whatever today is.
    $page->click('[data-test="kind-revenue"]');
    expect(browserSoon($page, "window.location.pathname === '/admin/reports/revenue' && document.querySelector('[data-test=\"by-month\"]') !== null"))->toBeTrue();
    $page->click('[data-test="show"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"report\"]') !== null && document.querySelector('[data-test=\"download\"]') !== null"))->toBeTrue();
    $page->click('[data-test="by-month"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"changed\"]') !== null && document.querySelector('[data-test=\"download\"]') === null && document.querySelector('[data-test=\"report\"]') !== null"))->toBeTrue();
    $page->click('[data-test="show"]');
    expect(browserSoon($page, "new URLSearchParams(window.location.search).get('by') === 'month' && document.querySelector('[data-test=\"changed\"]') === null && document.querySelector('[data-test=\"download\"]') !== null"))->toBeTrue();

    expect(reportsBrowserFitsPhone($page))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

it('shows Revenue\'s sheets as tabs, says a report of now has no days, an empty sheet with no total, and a refusal in place', function () {
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

    // Days before any search was logged: only "Nothing for these days.", no table and no total.
    $page->navigate('/admin/reports/searches?store=ae&from=2025-01-01&to=2025-01-02', BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"empty\"]')?.textContent.includes('Nothing for these days.') === true"))->toBeTrue()
        ->and($page->script("document.querySelector('[data-test=\"report\"] table') === null && document.querySelector('[data-test=\"total\"]') === null"))->toBeTrue();

    // A refusal comes back in words, the choices kept.
    $page->navigate('/admin/reports/revenue?store=ae&from=2026-10-02&to=2026-10-01', BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"refusal\"]')?.textContent.includes('last day') === true"))->toBeTrue()
        ->and($page->script("document.querySelector('[data-test=\"report\"]') === null"))->toBeTrue();

    expect(reportsBrowserFitsPhone($page))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

it('draws a report in the account\'s language beside an English page: "—" for no value, counts grouped, the 50-rows line', function () {
    $page = reportsBrowser([OpsPermissions::REPORT_VIEW], 'ar');

    // Sales stands in from here: 51 sizes, so the preview shows 50 and reads its total apart (§4.6).
    $sales = Mockery::mock(SalesReports::class);
    $sales->shouldReceive('productSales')->andReturn(new ProductSalesPageDto(array_map(
        static fn (int $i): ProductSaleDto => new ProductSaleDto(sprintf('01jbxqz2y8k4n6p9r3t5v7%04d', $i), sprintf('01jbxqz2y8k4n6p9r3t6v7%04d', $i), $i === 0 ? 1234 : 1, Money::of(100, 'AED')),
        range(0, 50),
    ), null));
    $sales->shouldReceive('productSalesTotals')->andReturn(new ProductSalesTotalsDto(1284, Money::of(5100, 'AED')));
    app()->instance(SalesReports::class, $sales);

    // The page in English, the account in Arabic.
    $page->navigate('/admin/reports/product-sales', BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"person-menu\"]') !== null"))->toBeTrue();
    if ((string) $page->script('document.documentElement.lang') !== 'en') {
        $page->click('[data-test="language"]');
    }
    expect(browserSoon($page, "document.documentElement.lang === 'en'"))->toBeTrue();

    $today = reportsBrowserToday()->format('Y-m-d');
    $page->navigate("/admin/reports/product-sales?store=ae&from={$today}&to={$today}", BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"report\"]')?.getAttribute('dir') === 'rtl'"))->toBeTrue()
        ->and((string) $page->script("document.querySelector('[data-test=\"report-language\"]')?.textContent ?? ''"))->toBe('This report is in Arabic, your account\'s language.')
        ->and((int) $page->script("document.querySelectorAll('[data-test=\"row\"]').length"))->toBe(50)
        ->and((string) $page->script("document.querySelector('[data-test=\"more-rows\"]')?.textContent ?? ''"))->toBe('Showing the first 50 rows. Every row is in the Excel file.')
        // Units, the sixth column: grouped, in Latin digits.
        ->and((string) $page->script("document.querySelector('[data-test=\"row\"] td:nth-child(6)').textContent"))->toBe('1,234')
        // The total has no product, size or code: "—", never empty and never 0.
        ->and((string) $page->script("[...document.querySelectorAll('[data-test=\"total\"] td')].slice(1, 4).map((cell) => cell.textContent).join('|')"))->toBe('—|—|—')
        ->and((string) $page->script("document.querySelector('[data-test=\"total\"] td:nth-child(6)').textContent"))->toBe('1,284')
        ->and((bool) $page->script('/[٠-٩]/.test(document.body.textContent)'))->toBeFalse();

    expect(reportsBrowserFitsPhone($page))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

it('draws right to left in Arabic, in Latin digits, and fits a phone', function () {
    $run = substr((string) hrtime(true), -10);
    reportsBrowserSearch("بحث{$run}");
    $page = reportsBrowser([OpsPermissions::REPORT_VIEW], 'ar');
    $page->resize(375, 812);

    // The last 30 days to today: within the twelve months a Searches report may cover, whatever today is.
    $today = reportsBrowserToday();
    $page->navigate("/admin/reports/searches?store=ae&from={$today->subDays(30)->format('Y-m-d')}&to={$today->format('Y-m-d')}", BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.documentElement.dir === 'rtl' && document.querySelector('[data-test=\"report\"]')?.getAttribute('dir') === 'rtl'"))->toBeTrue()
        ->and(browserSoon($page, "[...document.querySelectorAll('[data-test=\"row\"]')].some((row) => row.textContent.includes('{$run}'))"))->toBeTrue()
        ->and((bool) $page->script('/[٠-٩]/.test(document.body.textContent)'))->toBeFalse()
        ->and(browserSoon($page, 'document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});
