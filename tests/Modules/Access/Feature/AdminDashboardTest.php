<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Modules\Feedback\Application\FeedbackPermissions;
use Modules\Ops\Application\OpsPermissions;
use Modules\Platform\Public\PlatformPermissions;
use Modules\Sales\Application\SalesPermissions;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Sales\Support\CheckoutFixtures as Cx;
use Tests\TestCase;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

beforeEach(function () {
    // A session kept across requests, and no random sweep of it in a count (lesson 182).
    config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

/*
| The admin dashboard over HTTP (dashboard.md §2, §3.3, §5, §7): which sections each reader is handed - each by the
| permission behind it - the period in the address, the greeting, and the figures kept 5 minutes with Refresh (D11).
| The clock is TestCase::FROZEN_AT, then half an hour on: Monday 2 November 2026, 15:30 in Riyadh.
*/

function adminDashboardSignIn(string $staffId): AdminBrowser
{
    $browser = new AdminBrowser('10.12.0.'.random_int(20, 250));

    $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'),
        'password' => Fx::STAFF_PASSWORD,
    ])->assertRedirect('/admin/sign-in/code');

    $browser->post('/admin/sign-in/code', [
        'code' => RecordingSecurityMessages::installed()->lastCode(),
    ])->assertRedirect('/admin');

    return $browser;
}

/**
 * An order placed now in Saudi Arabia, through Sales' own use cases - the customer acting only meanwhile, so the
 * panel's requests act as whoever signs in (lesson of 2026-11-11, Content PR 8).
 */
function adminDashboardOrder(): void
{
    Fx::asSystem(static function (): void {
        $ready = Cx::ready();
        Cx::place(Cx::start($ready['address'])->id);
    });
}

/** Half an hour after the orders: a period runs up to the moment it is read, not included. */
function adminDashboardLater(int $minutes = 30): void
{
    $later = CarbonImmutable::parse(TestCase::FROZEN_AT)->addMinutes($minutes);
    CarbonImmutable::setTestNow($later);
    Carbon::setTestNow($later);
}

/**
 * @param  list<array<string, mixed>>  $blocks
 * @return list<string>
 */
function adminDashboardKeys(array $blocks): array
{
    return array_map(static fn (array $block): string => (string) $block['key'], $blocks);
}

/** @return array<string, mixed> the page's props */
function adminDashboardPage(AdminBrowser $browser, string $address = '/admin'): array
{
    $props = [];
    $browser->get($address)->assertOk()->assertInertia(function (AssertableInertia $inertia) use (&$props): void {
        $inertia->component('Admin/Home');
        $props = $inertia->toArray()['props'];
    });

    return $props;
}

it('hands a Super Admin every section in the spec\'s order, the greeting row\'s report, and This Month first', function () {
    adminDashboardOrder();
    adminDashboardLater();
    $browser = adminDashboardSignIn(Fx::staff(superAdmin: true, firstName: 'Sara'));

    $page = adminDashboardPage($browser);

    expect(adminDashboardKeys($page['sections']))->toBe([
        'sales.sales', 'sales.orders', 'sales.average_order', 'sales.new_customers',
        'sales.by_day', 'sales.audience', 'sales.orders_now', 'feedback.rating',
        'sales.recent_orders', 'sales.best_sellers', 'sales.distribution',
    ])
        ->and(adminDashboardKeys($page['actions']))->toBe(['ops.report'])
        ->and($page['actions'][0]['action']['fields'])->toBe(['store' => '', 'day' => ''])
        ->and($page['period'])->toBe('month')
        ->and(array_column($page['periods'], 'value'))->toBe(['today', '7days', 'month', '3months', 'year'])
        ->and(array_column($page['periods'], 'label'))->toBe(['Today', '7 Days', 'This Month', '3 Months', 'Year'])
        // 15:30 by the base store's clock for All Stores.
        ->and($page['greeting'])->toBe('Good afternoon, Sara')
        ->and($page['readAt'])->toBe('2026-11-02T12:30:00+00:00')
        ->and($page['sections'][0]['size'])->toBe('quarter')
        ->and($page['sections'][0]['metrics'][0]['lines'])->toHaveCount(3)
        // Needs Attention: two stores have no Terms and Conditions published.
        ->and(adminDashboardKeys($page['cards']))->toContain('sales.terms_missing');
});

it('keeps the period in the address, This Month for anything it does not know, and the store beside it', function () {
    adminDashboardLater();
    $browser = adminDashboardSignIn(Fx::staff(superAdmin: true));

    expect(adminDashboardPage($browser, '/admin?period=year&store=eg')['period'])->toBe('year')
        ->and(adminDashboardPage($browser, '/admin?period=year&store=eg')['storeCode'])->toBe('eg')
        ->and(adminDashboardPage($browser, '/admin?period=week')['period'])->toBe('month')
        ->and(adminDashboardPage($browser, '/admin?period[]=year')['period'])->toBe('month');
});

it('greets by the chosen store\'s clock: evening in Riyadh at 17:00, still afternoon in Cairo at 16:00', function () {
    adminDashboardLater(2 * 60);
    $browser = adminDashboardSignIn(Fx::staff(superAdmin: true, firstName: 'Sara'));

    expect(adminDashboardPage($browser, '/admin?store=sa')['greeting'])->toBe('Good evening, Sara')
        ->and(adminDashboardPage($browser, '/admin?store=eg')['greeting'])->toBe('Good afternoon, Sara');
});

it('hands each reader the sections of the permissions they hold, and nothing of the others', function (array $permissions, array $sections, array $actions) {
    adminDashboardOrder();
    adminDashboardLater();
    $browser = adminDashboardSignIn(Fx::staffWith(array_values(array_map(strval(...), $permissions)), ['sa']));

    $page = adminDashboardPage($browser);

    expect($page['storeCode'])->toBe('sa')
        ->and(adminDashboardKeys($page['sections']))->toBe($sections)
        ->and(adminDashboardKeys($page['actions']))->toBe($actions);
})->with([
    'orders: Sales\' sections, New Customers with them (D10)' => [[SalesPermissions::ORDER_VIEW], [
        'sales.sales', 'sales.orders', 'sales.average_order', 'sales.new_customers', 'sales.by_day', 'sales.audience', 'sales.orders_now',
        'sales.recent_orders', 'sales.best_sellers', 'sales.distribution',
    ], []],
    'reviews: the average rating alone' => [[FeedbackPermissions::REVIEW_MODERATE], ['feedback.rating'], []],
    'the daily report: its button alone' => [[OpsPermissions::REPORT_DAILY], [], ['ops.report']],
    'none of them' => [[PlatformPermissions::SETTINGS_VIEW], [], []],
]);

it('offers the report of one store to its maker, and the report of every store to a Super Admin only', function () {
    adminDashboardLater();
    $maker = adminDashboardSignIn(Fx::staffWith([OpsPermissions::REPORT_DAILY], ['*']));
    $page = adminDashboardPage($maker);

    // All Stores for them (held in every store), but the all-stores report is a Super Admin's (§2.1).
    expect($page['storeCode'])->toBeNull()
        ->and($page['actions'])->toBe([])
        ->and(adminDashboardPage($maker, '/admin?store=eg')['actions'][0]['action']['fields'])->toBe(['store' => Fx::storeId('eg'), 'day' => '']);
});

it('keeps each card\'s figures 5 minutes for the reader, store and period, and Refresh reads them again', function () {
    // Three orders, two kept out of the count for now (a test order is never counted): the browser's session must not
    // see other accounts made after it signs in (AGENT-BRIEF §8, 8), so the figures change in the orders' own rows.
    adminDashboardOrder();
    adminDashboardOrder();
    adminDashboardOrder();
    $later = DB::table('sales.orders')->orderBy('id')->skip(1)->limit(2)->pluck('id')->all();
    DB::table('sales.orders')->whereIn('id', $later)->update(['is_test' => true]);
    adminDashboardLater();
    $browser = adminDashboardSignIn(Fx::staffWith([SalesPermissions::ORDER_VIEW], ['sa']));
    $orders = static fn (array $page): int => (int) $page['sections'][1]['metrics'][0]['lines'][0]['value']['value'];

    expect($orders(adminDashboardPage($browser)))->toBe(1);

    // A minute later another order counts: the kept figures still say one, and say when they were read.
    DB::table('sales.orders')->where('id', $later[0])->update(['is_test' => false]);
    adminDashboardLater(31);
    $kept = adminDashboardPage($browser);
    expect($orders($kept))->toBe(1)
        ->and($kept['readAt'])->toBe('2026-11-02T12:30:00+00:00')
        // Another period is kept apart, read now.
        ->and($orders(adminDashboardPage($browser, '/admin?period=today')))->toBe(2);

    // Refresh forgets them, and opens the home again on the same store and period.
    $browser->post('/admin/home/refresh', ['store' => 'sa', 'period' => 'month'])->assertRedirect('/admin?store=sa&period=month');
    $fresh = adminDashboardPage($browser);
    expect($orders($fresh))->toBe(2)
        ->and($fresh['readAt'])->toBe('2026-11-02T12:31:00+00:00');

    // Past the 5 minutes, read again by itself.
    DB::table('sales.orders')->where('id', $later[1])->update(['is_test' => false]);
    adminDashboardLater(37);
    expect($orders(adminDashboardPage($browser)))->toBe(3);
});

it('keeps one reader\'s figures from another: each is read for whoever asks', function () {
    adminDashboardOrder();
    adminDashboardOrder();
    $later = (string) DB::table('sales.orders')->orderByDesc('id')->value('id');
    DB::table('sales.orders')->where('id', $later)->update(['is_test' => true]);
    adminDashboardLater();
    $first = adminDashboardSignIn(Fx::staffWith([SalesPermissions::ORDER_VIEW], ['sa']));
    $second = adminDashboardSignIn(Fx::staffWith([SalesPermissions::ORDER_VIEW], ['sa']));
    adminDashboardPage($first);
    DB::table('sales.orders')->where('id', $later)->update(['is_test' => false]);
    adminDashboardLater(31);

    expect((int) adminDashboardPage($second)['sections'][1]['metrics'][0]['lines'][0]['value']['value'])->toBe(2)
        ->and((int) adminDashboardPage($first)['sections'][1]['metrics'][0]['lines'][0]['value']['value'])->toBe(1);
});

it('keeps one store\'s figures from another\'s, and one language\'s words from the other\'s', function () {
    // Two orders in Saudi Arabia, one in Egypt: a reader of both opens each store in turn, inside the 5 minutes.
    adminDashboardOrder();
    adminDashboardOrder();
    Fx::asSystem(static function (): void {
        $ready = Cx::ready(storeCode: 'eg');
        Cx::place(Cx::start($ready['address'], storeCode: 'eg')->id, 'eg');
    });
    adminDashboardLater();
    $browser = adminDashboardSignIn(Fx::staffWith([SalesPermissions::ORDER_VIEW], ['sa', 'eg']));
    $orders = static fn (array $page): int => (int) $page['sections'][1]['metrics'][0]['lines'][0]['value']['value'];
    $title = static fn (array $page): string => (string) $page['sections'][1]['metrics'][0]['label'];

    expect($orders(adminDashboardPage($browser, '/admin?store=sa')))->toBe(2)
        ->and($orders(adminDashboardPage($browser, '/admin?store=eg')))->toBe(1)
        ->and($title(adminDashboardPage($browser, '/admin?store=sa')))->toBe('Orders');

    // The same store and period in Arabic: its words, not the English ones kept a moment ago.
    $browser->post('/admin/preferences', ['preference' => 'locale', 'value' => 'ar']);
    expect($title(adminDashboardPage($browser, '/admin?store=sa')))->toBe('الطلبات');
});

it('refuses Refresh for a store that is not the reader\'s, as the home does', function () {
    $browser = adminDashboardSignIn(Fx::staffWith([SalesPermissions::ORDER_VIEW], ['sa']));

    $browser->post('/admin/home/refresh', ['store' => 'eg', 'period' => 'month'])->assertForbidden();
});

it('reads a warm home in a recorded number of queries, whatever the sections', function (Closure $reader, int $recorded) {
    adminDashboardOrder();
    adminDashboardLater();
    $browser = adminDashboardSignIn($reader());
    $count = static function () use ($browser): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $browser->get('/admin')->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };
    // Cold: every section read once and kept (D11).
    $count();
    expect($count())->toBe($recorded);
})->with([
    // Measured 2026-11-21, warm. The page's own is **1** for both: every offered card's kept figures in one read of
    // the cache (D11), however many sections - far under the admin budget of 15 (frontend.md §5); cold, every module
    // reads its own. The rest is the panel's frame: the session (2: its row, its write), the cache reads of the
    // stores, permissions and settings (6), the person (1), and the menu's counts the reader is shown - for a Super
    // Admin low stock, prices, sync, shipping, failed jobs and the inbox (6); for this staff member the inbox (1).
    'a Super Admin, every section' => [fn () => Fx::staff(superAdmin: true), 16],
    'staff viewing one store\'s orders' => [fn () => Fx::staffWith([SalesPermissions::ORDER_VIEW], ['sa']), 11],
]);
