<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Modules\Access\Public\Enums\StaffStatus;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Inventory\Application\Stock\LowStockCount;
use Modules\Pricing\Application\Command\AddCategoryDiscount\AddCategoryDiscount;
use Modules\Pricing\Application\Command\AddCategoryDiscount\AddCategoryDiscountHandler;
use Modules\Pricing\Application\Command\AddSale\AddSale;
use Modules\Pricing\Application\Command\AddSale\AddSaleHandler;
use Modules\Sync\Application\Command\RunRead\RunRead;
use Modules\Sync\Application\Command\RunRead\RunReadHandler;
use Modules\Sync\Application\Report\SyncReportCount;
use Modules\Sync\Application\SyncPermissions as P;
use Modules\Sync\Domain\ValueObject\ProviderValue;
use Symfony\Component\HttpFoundation\Response;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Sync\Support\FakeProvider;

use function Pest\Laravel\seed;

/*
|--------------------------------------------------------------------------
| Sync's screens over HTTP (sync.md §4.1, stage 5's screens step 6 - the server's side; the pages are
| placeholders until step 7): the Sync Report's props, Problems or Reads, a store not wired showing its
| Reads (Q35); a read's page and its not-found (§9.3 #29); the Provider page, a Super Admin's; each change's
| toast and a refusal said on the screen - a provider's setting under its own box (Q34); the menu's two
| entries with the report's count; each page's own queries within 15, counted warm.
|
| The reads and the commands have their own tests; these are about the screens.
|--------------------------------------------------------------------------
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
    Queue::fake();
    // Pinned an hour behind the real clock, never to a fixed date: sign-in codes and sessions are
    // checked against the database's own clock, which a fixed date falls behind (lesson 221).
    CarbonImmutable::setTestNow(CarbonImmutable::now()->subHour()->startOfSecond());
    FakeProvider::bind(fields: ['database']);
});

afterEach(function () {
    CarbonImmutable::setTestNow();
});

/** Signed in through the panel's door as this staff member, the panel in English - or the language given. */
function syncScreensAs(string $staffId, string $locale = 'en'): AdminBrowser
{
    DB::table('access.staff_users')->where('id', $staffId)->update(['locale' => $locale]);
    $browser = new AdminBrowser('10.9.7.'.random_int(20, 250));

    $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'),
        'password' => Fx::STAFF_PASSWORD,
    ])->assertRedirect('/admin/sign-in/code');

    $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode()])->assertRedirect('/admin');

    return $browser;
}

/**
 * @param  list<string>  $permissions
 * @param  list<string>  $stores
 */
function syncScreens(array $permissions = [P::REPORT_VIEW, P::READ_RUN], array $stores = ['sa']): AdminBrowser
{
    return syncScreensAs(Fx::staffWith($permissions, $stores));
}

function syncScreensSuper(): AdminBrowser
{
    return syncScreensAs(Fx::staff(StaffStatus::Active, superAdmin: true));
}

/** KSA wired, as a Super Admin wires it through its page; its first read run, with a product it found. */
function syncScreensWired(): string
{
    $ready = Px::ready(['60 cm']);
    Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId('sa'), $ready['product'], true)));
    $code = (string) DB::table('catalog.variants')->where('id', $ready['variants'][0])->value('code');
    FakeProvider::bound()->values = [new ProviderValue($code, '120.50', 7), new ProviderValue('987654', '5', 1)];
    syncScreensSuper()->post('/admin/sync/provider/wire', ['store' => 'sa', 'provider' => 'ODOO', 'address' => 'https://odoo.test/api', 'key' => 'k', 'secret' => 's', 'settings' => ['database' => 'tw']]);
    $read = (string) DB::table('sync.reads')->value('id');
    Fx::asSystem(fn () => app(RunReadHandler::class)->handle(new RunRead($read)));

    return $read;
}

/**
 * The worst case a page shows (the review of step 6): KSA wired, and one read that stopped an offer, made a
 * category discount skip a size, met a bad value and an unknown code; a failed read's entry; reads asked
 * by several people. The read's id.
 */
function syncScreensWorst(): string
{
    $category = Px::category('Kitchens');
    $ready = Px::ready(['60 cm', '80 cm', '100 cm'], $category);
    [$onSale, $discounted, $bad] = $ready['variants'];
    Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId('sa'), $ready['product'], true)));
    syncScreensSuper()->post('/admin/sync/provider/wire', ['store' => 'sa', 'provider' => 'ODOO', 'address' => 'https://odoo.test/api', 'key' => 'k', 'secret' => 's', 'settings' => ['database' => 'tw']]);
    Fx::asSystem(fn () => app(RunReadHandler::class)->handle(new RunRead((string) DB::table('sync.reads')->value('id'))));
    DB::table('pricing.retail_prices')->insert(array_map(static fn (string $size): array => ['store_id' => Fx::storeId('sa'), 'variant_id' => $size, 'amount_minor' => 20000, 'currency_code' => 'SAR', 'source' => 'PROVIDER', 'updated_at' => now()], [$onSale, $discounted]));
    Fx::asSystem(fn (): string => app(AddSaleHandler::class)->handle(new AddSale(Fx::storeId('sa'), null, [$onSale], null, '160', null, null)));
    Fx::asSystem(fn (): string => app(AddCategoryDiscountHandler::class)->handle(new AddCategoryDiscount(Fx::storeId('sa'), $category, null, '50', 'BOTH', null, null)));
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addHour());
    $code = static fn (string $size): string => (string) DB::table('catalog.variants')->where('id', $size)->value('code');
    FakeProvider::bound()->values = [new ProviderValue($code($onSale), '400', null), new ProviderValue($code($discounted), '120', null), new ProviderValue($code($bad), '0', 2), new ProviderValue('987654', '5', 1)];
    $staff = [Fx::staff(firstName: 'Huda'), Fx::staff(firstName: 'Omar'), Fx::staff(firstName: 'Lina')];
    DB::table('sync.reads')->insert(array_map(static fn (string $asker, int $n): array => [
        'id' => strtolower((string) Str::ulid()), 'store_id' => Fx::storeId('sa'), 'kind' => 'NOW', 'state' => 'DONE', 'requested_by_type' => 'STAFF', 'requested_by_id' => $asker,
        'queued_at' => now()->subMinutes(10 - $n), 'started_at' => now()->subMinutes(10 - $n), 'finished_at' => now()->subMinutes(9 - $n),
    ], $staff, array_keys($staff)));
    $read = strtolower((string) Str::ulid());
    DB::table('sync.reads')->insert(['id' => $read, 'store_id' => Fx::storeId('sa'), 'kind' => 'NOW', 'state' => 'QUEUED', 'requested_by_type' => 'STAFF', 'requested_by_id' => $staff[0], 'queued_at' => now()]);
    Fx::asSystem(fn () => app(RunReadHandler::class)->handle(new RunRead($read)));
    DB::table('sync.report_entries')->insert(['store_id' => Fx::storeId('sa'), 'kind' => 'READ_FAILED', 'code' => null, 'variant_id' => null, 'sale_id' => null, 'detail' => json_encode(['read' => $read, 'error' => 'WORKER_GONE']), 'opened_at' => now(), 'seen_at' => now()]);

    return $read;
}

/**
 * @param  TestResponse<Response>  $response
 * @return array<string, mixed>
 */
function syncScreensProps(TestResponse $response, string $component): array
{
    $props = [];

    $response->assertOk()->assertInertia(function (AssertableInertia $page) use (&$props, $component): void {
        $page->component($component);
        $props = $page->toArray()['props'];
    });

    return $props;
}

/**
 * @param  TestResponse<Response>  $response
 * @return array<string, mixed>
 */
function syncScreensErrors(TestResponse $response): array
{
    $errors = AdminBrowser::flashed($response, 'errors');

    if ($errors instanceof ViewErrorBag) {
        return $errors->getBag('default')->toArray();
    }

    return is_array($errors) ? ($errors['default']['messages'] ?? []) : [];
}

/**
 * How many queries the page asks for itself, opened a second time (warm): those made while Sync's own code
 * runs - the panel's frame counted apart, the report's count beside the menu entry with it, and Low Stock's
 * beside its own (a Super Admin's menu): counted live while no count is kept yet (the queue is faked), it
 * asks Sync's `StoreWiring` binding, so its queries pass through Sync's files without being the page's -
 * as Inventory's own test leaves them out - and never the cache's rows.
 */
function syncScreensOwnQueries(AdminBrowser $browser, string $uri): int
{
    $browser->get($uri)->assertOk();
    /** @var ArrayObject<int, string> $own */
    $own = new ArrayObject;

    DB::listen(function (QueryExecuted $query) use ($own): void {
        if (str_contains($query->sql, '"cache"')) {
            return;
        }

        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);

        foreach ($frames as $frame) {
            if (in_array($frame['class'] ?? '', [SyncReportCount::class, LowStockCount::class], true)) {
                return;
            }
        }

        foreach ($frames as $frame) {
            if (str_contains(str_replace(chr(92), '/', $frame['file'] ?? ''), '/src/Modules/Sync/')) {
                $own[] = $query->sql;

                return;
            }
        }
    });

    $browser->get($uri)->assertOk();

    return count($own);
}

/**
 * The Sync group's entries this person was offered, by key, with their counts.
 *
 * @return array<string, mixed>
 */
function syncScreensMenu(AdminBrowser $browser): array
{
    $found = [];

    $browser->get('/admin')->assertOk()->assertInertia(function (AssertableInertia $inertia) use (&$found): void {
        foreach ($inertia->toArray()['props']['menu'] ?? [] as $group) {
            foreach (is_array($group) ? ($group['entries'] ?? []) : [] as $entry) {
                if (($entry['module'] ?? null) === 'sync') {
                    $found[$entry['key']] = $entry['count'] ?? null;
                }
            }
        }
    });

    return $found;
}

describe('the Sync Report (Y1)', function () {
    it('shows the status line and Problems, the words in the page\'s language', function () {
        syncScreensWired();
        $page = syncScreensProps(syncScreens([P::REPORT_VIEW, P::READ_RUN, CatalogPermissions::PRODUCT_VIEW])->get('/admin/sync'), 'Sync/Admin/Report');

        expect($page['tab'])->toBe('problems')
            ->and([$page['status']['providerName'], $page['status']['state']])->toBe(['Odoo', 'WORKING'])
            ->and([$page['storeFrame']['storeCode'], $page['storeFrame']['wired'], $page['storeFrame']['mayRun'], $page['storeFrame']['mayViewProducts'], $page['storeFrame']['mayWire']])->toBe(['sa', true, true, true, false])
            ->and($page['problems']['total'])->toBe(1)
            ->and($page['problems']['entries'][0]['about']['kind'])->toBe('UNKNOWN_CODE')
            ->and($page['reads'])->toBeNull()
            ->and($page['translations']['sync::admin_report.title'])->toBe('Sync Report');
    });

    it('shows the Reads when asked; a store not wired only its Reads (Q35)', function () {
        $browser = syncScreens();
        $notWired = syncScreensProps($browser->get('/admin/sync?tab=problems'), 'Sync/Admin/Report');
        syncScreensWired();
        $reads = syncScreensProps($browser->get('/admin/sync?tab=reads'), 'Sync/Admin/Report');

        expect([$notWired['tab'], $notWired['status']['state'], $notWired['problems'], $notWired['reads']['total']])->toBe(['reads', 'NOT_WIRED', null, 0])
            ->and([$reads['tab'], $reads['reads']['total'], $reads['reads']['reads'][0]['kind'], $reads['problems']])->toBe(['reads', 1, 'NOW', null])
            // The Problems tab's trigger keeps its count while Reads is shown (step 7): the unknown code.
            ->and([$notWired['reads']['openEntries'], $reads['reads']['openEntries']])->toBe([0, 1]);
    });

    it('is refused to someone without View the Sync Report there, and for a store not theirs', function () {
        syncScreens([P::READ_RUN])->get('/admin/sync')->assertForbidden();
        syncScreens()->get('/admin/sync?store=ae')->assertForbidden();
    });

    it('queues Sync Now, Retry Read, and dismisses an entry - each said in a toast - and says a refusal on the screen', function () {
        syncScreensWired();
        $browser = syncScreens();
        $unknown = (int) DB::table('sync.report_entries')->where('kind', 'UNKNOWN_CODE')->value('id');

        $now = $browser->post('/admin/sync/now', ['store' => 'sa']);
        $retry = $browser->post("/admin/sync/entries/{$unknown}/retry");
        $dismissed = $browser->post("/admin/sync/entries/{$unknown}/dismiss");
        $again = $browser->post("/admin/sync/entries/{$unknown}/dismiss");

        expect([AdminBrowser::flashed($now, 'status'), AdminBrowser::flashed($retry, 'status'), AdminBrowser::flashed($dismissed, 'status')])->toBe(['Read queued', 'Read queued', 'Entry dismissed'])
            ->and(DB::table('sync.reads')->where('state', 'QUEUED')->pluck('kind')->sort()->values()->all())->toBe(['CODE', 'NOW'])
            ->and(syncScreensErrors($again)['form'] ?? null)->toBe(['There is no such entry in the Sync report.']);

        // Without Run Sync Reads: refused on the screen, nothing queued.
        $viewer = syncScreens([P::REPORT_VIEW]);
        $refused = $viewer->post('/admin/sync/now', ['store' => 'sa']);

        expect(syncScreensErrors($refused))->toHaveKey('form')
            ->and(DB::table('sync.reads')->where('state', 'QUEUED')->count())->toBe(2);
    });
});

describe('a read (Y2)', function () {
    it('shows a read\'s header and the section asked, a page at a time', function () {
        $read = syncScreensWired();
        $browser = syncScreens();

        $prices = syncScreensProps($browser->get("/admin/sync/reads/{$read}"), 'Sync/Admin/Read');
        $reported = syncScreensProps($browser->get("/admin/sync/reads/{$read}?section=reported&page=1"), 'Sync/Admin/Read');

        expect([$prices['storeCode'], $prices['section'], count($prices['lines']), $prices['read']['state']])->toBe(['sa', 'PRICES', 1, 'DONE'])
            ->and($prices['sections'])->toBe(['prices' => 1, 'stock' => 1, 'reported' => 1, 'codes' => 0])
            ->and([$reported['section'], count($reported['reported']), $reported['lines']])->toBe(['REPORTED', 1, []]);
    });

    it('is the not-found page when pruned, another store\'s or never one', function () {
        $read = syncScreensWired();

        syncScreens([P::REPORT_VIEW], ['ae'])->get("/admin/sync/reads/{$read}")->assertNotFound();
        syncScreens()->get('/admin/sync/reads/01jbzzzzzzzzzzzzzzzzzzzzzz')->assertNotFound();
        syncScreens()->get('/admin/sync/reads/nope')->assertNotFound();
    });
});

describe('the Provider page (Y3)', function () {
    it('is a Super Admin\'s: the connection, never the key; refused to anyone else', function () {
        syncScreensWired();
        $page = syncScreensProps(syncScreensSuper()->get('/admin/sync/provider'), 'Sync/Admin/Provider');

        expect([$page['connection']['provider'], $page['connection']['keySet'], $page['connection']['settings'], $page['connection']['fields']])->toBe(['ODOO', true, ['database' => 'tw'], ['database']])
            ->and($page['connection']['noticeAddress'])->toEndWith('/sync/notify/'.Fx::storeId('sa'))
            ->and($page['storeFrame']['mayWire'])->toBeTrue()
            ->and(str_contains((string) json_encode($page['connection']), '"k"'))->toBeFalse();

        syncScreens()->get('/admin/sync/provider')->assertForbidden();
    });

    it('wires, changes and unwires a store, each said in a toast; a setting refused under its own box (Q34)', function () {
        $browser = syncScreensSuper();

        $refused = $browser->post('/admin/sync/provider/wire', ['store' => 'sa', 'provider' => 'ODOO', 'address' => 'https://odoo.test/api', 'key' => 'k', 'secret' => 's', 'settings' => ['database' => ' ']]);
        $wired = $browser->post('/admin/sync/provider/wire', ['store' => 'sa', 'provider' => 'ODOO', 'address' => 'https://odoo.test/api', 'key' => 'k', 'secret' => 's', 'settings' => ['database' => 'tw']]);
        $changed = $browser->post('/admin/sync/provider/change', ['store' => 'sa', 'provider' => 'ODOO', 'address' => 'https://odoo.test/v2', 'key' => '', 'secret' => '', 'settings' => ['database' => 'tw']]);
        $unwired = $browser->post('/admin/sync/provider/unwire', ['store' => 'sa']);

        expect(syncScreensErrors($refused))->toBe(['form' => ['The provider\'s setting "database" is not valid.'], 'settings.database' => ['The provider\'s setting "database" is not valid.']])
            ->and([AdminBrowser::flashed($wired, 'status'), AdminBrowser::flashed($changed, 'status'), AdminBrowser::flashed($unwired, 'status')])->toBe(['Store wired', 'Connection changed', 'Store unwired'])
            ->and(DB::table('sync.connections')->count())->toBe(0);
    });

    it('says the provider\'s reason when its test fails, saving nothing', function () {
        FakeProvider::bound()->failure = 'Odoo answered 401.';

        $refused = syncScreensSuper()->post('/admin/sync/provider/wire', ['store' => 'sa', 'provider' => 'ODOO', 'address' => 'https://odoo.test/api', 'key' => 'k', 'secret' => 's', 'settings' => ['database' => 'tw']]);

        expect(syncScreensErrors($refused)['form'] ?? null)->toBe(['Failed to read the provider: Odoo answered 401.'])
            ->and(DB::table('sync.connections')->count())->toBe(0);
    });
});

describe('what a form names (the review of step 6)', function () {
    it('refuses an action that names no store, or a store the reader does not hold, queuing nothing', function () {
        syncScreensWired();
        DB::table('sync.reads')->delete();
        $browser = syncScreens();

        $none = $browser->post('/admin/sync/now', []);
        $other = $browser->post('/admin/sync/now', ['store' => 'ae']);

        expect(syncScreensErrors($none))->toHaveKey('form')
            ->and(syncScreensErrors($other))->toHaveKey('form')
            ->and(AdminBrowser::flashed($none, 'status'))->toBeNull()
            ->and(DB::table('sync.reads')->count())->toBe(0);
    });

    it('refuses wiring, changing and unwiring to someone who is not a Super Admin, changing nothing', function () {
        syncScreensWired();
        $browser = syncScreens([P::REPORT_VIEW, P::READ_RUN]);
        $form = ['store' => 'sa', 'provider' => 'ODOO', 'address' => 'https://odoo.test/other', 'key' => 'k2', 'secret' => 's2', 'settings' => ['database' => 'tw']];

        $refused = [
            $browser->post('/admin/sync/provider/wire', $form),
            $browser->post('/admin/sync/provider/change', $form),
            $browser->post('/admin/sync/provider/unwire', ['store' => 'sa']),
        ];

        expect(array_map(static fn ($response): bool => array_key_exists('form', syncScreensErrors($response)), $refused))->toBe([true, true, true])
            ->and(DB::table('sync.connections')->pluck('address')->all())->toBe(['https://odoo.test/api']);
    });
});

describe('a read\'s lines on an Arabic page', function () {
    it('writes a price as Pricing writes money, in Latin digits with the currency\'s sign, and a stock number as it is', function () {
        $read = syncScreensWired();
        $browser = syncScreensAs(Fx::staffWith([P::REPORT_VIEW], ['sa']), 'ar');

        $prices = syncScreensProps($browser->get("/admin/sync/reads/{$read}"), 'Sync/Admin/Read');
        $stock = syncScreensProps($browser->get("/admin/sync/reads/{$read}?section=stock"), 'Sync/Admin/Read');

        expect([$prices['lines'][0]['before'], $prices['lines'][0]['after']])->toBe([null, "120.50 \u{20C1}"])
            ->and([$stock['lines'][0]['before'], $stock['lines'][0]['after']])->toBe(['0', '7'])
            ->and($prices['translations']['sync::admin_report.read.sections.PRICES'])->toBe('الأسعار المتغيّرة');
    });
});

describe('the menu and the budget', function () {
    it('offers the Sync Report with its open entries, and the Provider page to a Super Admin only', function () {
        syncScreensWired();

        expect(syncScreensMenu(syncScreens([P::REPORT_VIEW])))->toBe(['report' => 1])
            ->and(syncScreensMenu(syncScreensSuper()))->toBe(['report' => 1, 'provider' => null])
            ->and(syncScreensMenu(syncScreens([P::READ_RUN])))->toBe([]);
    });

    it('reads each page\'s own queries within 15, recorded', function () {
        $read = syncScreensWired();
        $browser = syncScreens([P::REPORT_VIEW, P::READ_RUN, CatalogPermissions::PRODUCT_VIEW]);
        $counts = [
            'problems' => syncScreensOwnQueries($browser, '/admin/sync'),
            'reads' => syncScreensOwnQueries($browser, '/admin/sync?tab=reads'),
            'read' => syncScreensOwnQueries($browser, "/admin/sync/reads/{$read}"),
            'read reported' => syncScreensOwnQueries($browser, "/admin/sync/reads/{$read}?section=reported"),
        ];
        $counts['provider'] = syncScreensOwnQueries(syncScreensSuper(), '/admin/sync/provider');

        expect(array_filter($counts, static fn (int $count): bool => $count > 15))->toBe([])
            // Measured, so a page that grows is seen.
            ->and($counts)->toBe(['problems' => 4, 'reads' => 6, 'read' => 8, 'read reported' => 5, 'provider' => 5]);
    });

    it('reads each page\'s own queries within 15 in the worst case: an offer stopped, a discount skipped, a bad value, an unknown code, a failed read, several askers', function () {
        $read = syncScreensWorst();
        $kinds = DB::table('sync.report_entries')->whereNull('closed_at')->orderBy('kind')->pluck('kind')->all();
        $browser = syncScreens([P::REPORT_VIEW, P::READ_RUN, CatalogPermissions::PRODUCT_VIEW]);
        $counts = [
            'problems' => syncScreensOwnQueries($browser, '/admin/sync'),
            'reads' => syncScreensOwnQueries($browser, '/admin/sync?tab=reads'),
            'read' => syncScreensOwnQueries($browser, "/admin/sync/reads/{$read}"),
            'read stock' => syncScreensOwnQueries($browser, "/admin/sync/reads/{$read}?section=stock"),
            'read reported' => syncScreensOwnQueries($browser, "/admin/sync/reads/{$read}?section=reported"),
        ];
        $counts['provider'] = syncScreensOwnQueries(syncScreensSuper(), '/admin/sync/provider');
        // The same pages read by a Super Admin: every store offered, every link drawn.
        $super = syncScreensSuper();
        $superCounts = [
            'problems' => syncScreensOwnQueries($super, '/admin/sync'),
            'reads' => syncScreensOwnQueries($super, '/admin/sync?tab=reads'),
            'read' => syncScreensOwnQueries($super, "/admin/sync/reads/{$read}"),
            'read stock' => syncScreensOwnQueries($super, "/admin/sync/reads/{$read}?section=stock"),
            'read reported' => syncScreensOwnQueries($super, "/admin/sync/reads/{$read}?section=reported"),
        ];

        expect($kinds)->toBe(['BAD_VALUE', 'DISCOUNT_SKIPPED', 'READ_FAILED', 'SALE_STOPPED', 'UNKNOWN_CODE'])
            ->and(array_filter([...array_values($counts), ...array_values($superCounts)], static fn (int $count): bool => $count > 15))->toBe([])
            ->and($superCounts)->toBe(['problems' => 12, 'reads' => 6, 'read' => 8, 'read stock' => 8, 'read reported' => 13])
            // Measured, so a page that grows is seen.
            ->and($counts)->toBe(['problems' => 12, 'reads' => 6, 'read' => 8, 'read stock' => 8, 'read reported' => 13, 'provider' => 5]);
    });
});
