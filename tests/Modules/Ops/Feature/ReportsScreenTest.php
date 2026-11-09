<?php

declare(strict_types=1);

use App\Http\FormErrors;
use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Modules\Catalog\Application\Search\SearchLog;
use Modules\Inventory\Public\InventoryPermissions;
use Modules\Ops\Application\OpsPermissions;
use Modules\Ops\Domain\Exception\InvalidOpsInput;
use Modules\Platform\Application\Query\StoreDirectory;
use Modules\Sales\Public\Contracts\SalesReports;
use Modules\Sales\Public\Dto\ProductSaleDto;
use Modules\Sales\Public\Dto\ProductSalesPageDto;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\Money;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\seed;

/*
| O8, the Reports page (ops.md §4.15): `/admin/reports` goes to the first kind offered; a plain visit to a kind gets the
| page, the preview read with the choices in its address - the store named by its code (owner, 2026-11-05) - and JSON
| stays for whoever asks, the store by its id (§4.6). A refusal of the choices comes back in words; a kind not offered
| is the 403 page. ReportRoutesTest keeps the JSON's and the file's own answers.
|
| Every helper here is named after this file's subject: a Pest file's functions are global.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

function reportsScreenStaff(string $staffId): AdminBrowser
{
    $browser = new AdminBrowser('10.9.6.'.random_int(20, 250));
    $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'),
        'password' => Fx::STAFF_PASSWORD,
    ])->assertRedirect('/admin/sign-in/code');
    $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode()])->assertRedirect('/admin');

    return $browser;
}

it('goes from its own address to the first kind offered, and refuses someone offered none', function () {
    reportsScreenStaff(Fx::staffWith([OpsPermissions::REPORT_VIEW], ['sa']))->get('/admin/reports')->assertRedirect('/admin/reports/revenue');

    reportsScreenStaff(Fx::staffWith([OpsPermissions::REPORT_DAILY], ['sa']))->get('/admin/reports')->assertForbidden();
});

it('opens on the first store that is on and this month to today in its time, with nothing shown yet', function () {
    $browser = reportsScreenStaff(Fx::staffWith([OpsPermissions::REPORT_VIEW], ['sa', 'ae']));
    $today = CarbonImmutable::now('Asia/Riyadh');

    $browser->get('/admin/reports/revenue')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Ops/Admin/Reports')
        ->where('report', 'revenue')
        // No Stock without inventory.stock.manage: its tab is not offered.
        ->where('reports', fn (Collection $reports): bool => $reports->pluck('kind')->all() === ['revenue', 'product-sales', 'searches', 'favourites'])
        ->where('reports.0.allStores', false)
        ->where('reports.0.stores.0.code', 'sa')
        ->where('zones.sa', 'Asia/Riyadh')
        ->missing('zones.all')
        ->where('opening', ['store' => 'sa', 'from' => $today->startOfMonth()->format('Y-m-d'), 'to' => $today->format('Y-m-d'), 'by' => 'day'])
        ->where('shown', null)
        ->where('preview', null)
        ->where('refusal', null)
        ->where('translations', fn (Collection $words): bool => $words->has('ops::admin_reports.show') && $words->has('admin.store.option_off')));
});

it('opens on today in the store\'s own time, not UTC\'s', function () {
    // 21:30 in UTC on the 2nd is 00:30 on the 3rd in Riyadh: later than the suite's clock, before anything is made.
    $late = CarbonImmutable::parse('2026-11-02T21:30:00Z');
    CarbonImmutable::setTestNow($late);
    Carbon\Carbon::setTestNow($late);
    $browser = reportsScreenStaff(Fx::staffWith([OpsPermissions::REPORT_VIEW], ['sa']));

    $browser->get('/admin/reports/revenue')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('opening.from', '2026-11-01')
        ->where('opening.to', '2026-11-03'));
});

it('shows the report the address names by its code, its download naming the store by id, and JSON beside it', function () {
    $sales = Mockery::mock(SalesReports::class);
    $sales->shouldReceive('productSales')->andReturn(new ProductSalesPageDto([new ProductSaleDto('01jbxqz2y8k4n6p9r3t5v7w1xa', '01jbxqz2y8k4n6p9r3t5v7w1xb', 3, Money::of(45_50, 'SAR'))], null));
    app()->instance(SalesReports::class, $sales);
    $browser = reportsScreenStaff(Fx::staffWith([OpsPermissions::REPORT_VIEW], ['sa']));
    $id = Fx::storeId('sa');

    $browser->get('/admin/reports/product-sales?store=SA&from=2026-01-01&to=2026-01-02')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('shown', ['store' => 'sa', 'from' => '2026-01-01', 'to' => '2026-01-02', 'by' => 'day'])
        ->where('opening', ['store' => 'sa', 'from' => '2026-01-01', 'to' => '2026-01-02', 'by' => 'day'])
        ->where('refusal', null)
        ->where('preview.title', 'Product Sales')
        ->where('preview.downloadUrl', "/admin/reports/product-sales/download?store={$id}&from=2026-01-01&to=2026-01-02")
        ->where('preview.sheets.0.rows', [['Saudi Arabia', '', '', '', 'SAR', 3, '45.50']])
        ->where('preview.sheets.0.totals', [['Total', null, null, null, 'SAR', 3, '45.50']]));

    expect(array_keys($browser->get("/admin/reports/product-sales?store={$id}&from=2026-01-01&to=2026-01-02", ['HTTP_ACCEPT' => 'application/json'])->assertOk()->json()))
        ->toBe(['report', 'locale', 'rightToLeft', 'title', 'subtitle', 'sheets', 'filename', 'downloadUrl']);
});

it('says a refusal of the choices in words, in place, keeping the choices', function (string $query, Closure $words) {
    $browser = reportsScreenStaff(Fx::staffWith([OpsPermissions::REPORT_VIEW], ['sa']));

    $browser->get('/admin/reports/revenue'.$query)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('Ops/Admin/Reports')
        ->where('preview', null)
        ->where('refusal', $words())
        ->where('shown.store', strtolower((string) preg_replace('/^\?store=([^&]*).*$/', '$1', $query))));
})->with([
    'the last day before the first' => ['?store=sa&from=2026-10-02&to=2026-10-01', fn (): string => FormErrors::message(new InvalidOpsInput('to', 'x'))],
    'before 2020' => ['?store=sa&from=2019-12-31&to=2020-01-02', fn (): string => __('ops::errors.report_range_refused.rules.years')],
    'a code no store has' => ['?store=zz&from=2026-10-01&to=2026-10-02', fn (): string => FormErrors::message(new Unauthorized(OpsPermissions::REPORT_VIEW))],
    'a store not theirs' => ['?store=ae&from=2026-10-01&to=2026-10-02', fn (): string => FormErrors::message(new Unauthorized(OpsPermissions::REPORT_VIEW))],
    'every store, not a Super Admin' => ['?store=all&from=2026-10-01&to=2026-10-02', fn (): string => FormErrors::message(new Unauthorized(OpsPermissions::REPORT_VIEW))],
]);

it('refuses a kind not offered with the 403 page, and an unknown kind as §4.6 does', function () {
    $browser = reportsScreenStaff(Fx::staffWith([OpsPermissions::REPORT_VIEW], ['sa']));

    $browser->get('/admin/reports/stock')->assertForbidden();
    $browser->get('/admin/reports/nothing')->assertUnprocessable();

    reportsScreenStaff(Fx::staffWith([OpsPermissions::REPORT_DAILY], ['sa']))->get('/admin/reports/revenue')->assertForbidden();
});

it('offers Stock where Inventory\'s job is held too, and shows it as it stands now', function () {
    $browser = reportsScreenStaff(Fx::staffWith([OpsPermissions::REPORT_VIEW, InventoryPermissions::STOCK_MANAGE], ['sa']));

    $browser->get('/admin/reports/stock?store=sa')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('report', 'stock')
        ->where('shown.store', 'sa')
        ->where('refusal', null)
        ->where('preview.report', 'stock'));
});

it('offers a Super Admin every store\'s report, its days in the base store\'s time', function () {
    // The base store made `ae` for this test, so its zone is told apart from the first store's (`sa`, Riyadh).
    DB::table('platform.stores')->where('code', 'sa')->update(['is_base' => false]);
    DB::table('platform.stores')->where('code', 'ae')->update(['is_base' => true, 'is_active' => true]);
    Fx::asSystem(fn () => app(StoreDirectory::class)->invalidate());
    $browser = reportsScreenStaff(Fx::staff(superAdmin: true));

    $browser->get('/admin/reports/revenue')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('reports.0.allStores', true)
        ->where('zones.sa', 'Asia/Riyadh')
        ->where('zones.all', 'Asia/Dubai')
        // Opens on the first store that is on, a Super Admin's too (§9.4).
        ->where('opening.store', 'sa'));

    $browser->get('/admin/reports/revenue?store=all&from=2026-10-01&to=2026-10-02&by=month')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('refusal', null)
        ->where('preview.downloadUrl', '/admin/reports/revenue/download?store=all&from=2026-10-01&to=2026-10-02&by=month')
        ->has('preview.sheets', 4));
});

it('offers the menu entry, under its own section, only to whoever may read a report', function () {
    $menu = static fn (string $staffId): array => array_map(
        static fn (array $group): string => $group['key'].':'.implode(',', array_column($group['entries'], 'key')),
        reportsScreenStaff($staffId)->get('/admin')->viewData('page')['props']['menu'],
    );

    expect($menu(Fx::staffWith([OpsPermissions::REPORT_VIEW], ['sa'])))->toContain('reports:reports')
        ->and(implode('|', $menu(Fx::staffWith([OpsPermissions::REPORT_DAILY], ['sa']))))->not->toContain('reports:reports');
});

it('names the Reports section in both languages', function () {
    expect(__('access::permission_groups.reports', [], 'en'))->toBe('Reports')
        ->and(__('access::permission_groups.reports', [], 'ar'))->toBe('التقارير')
        ->and((string) file_get_contents(base_path('resources/js/components/MenuIcon.tsx')))->toMatch('/^\s*reports: FileText,$/m');
});

it('reads the page in a recorded number of queries, before and after Show Report', function () {
    config(['session.lottery' => [0, 100]]);
    app(SearchLog::class)->record(Fx::storeId('sa'), 'en', 'oak door', 0);
    $browser = reportsScreenStaff(Fx::staffWith([OpsPermissions::REPORT_VIEW], ['sa']));
    $count = static function (string $address) use ($browser): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $browser->get($address)->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };
    // Warm the caches (the stores, the permissions), as a second request finds them.
    $count('/admin/reports/searches');
    $count('/admin/reports/searches?store=sa&from=2026-01-01&to=2026-12-31');

    // Measured 2026-11-05: 11 before Show Report (the panel's frame and the kinds on offer), 13 with a Searches preview.
    expect([$count('/admin/reports/searches'), $count('/admin/reports/searches?store=sa&from=2026-01-01&to=2026-12-31')])->toBe([11, 13]);
});
