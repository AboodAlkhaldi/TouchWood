<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Access\Public\Enums\StaffStatus;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Command\AddAttribute\AddAttribute;
use Modules\Catalog\Application\Command\AddAttribute\AddAttributeHandler;
use Modules\Catalog\Application\Command\AddAttributeValue\AddAttributeValue;
use Modules\Catalog\Application\Command\AddAttributeValue\AddAttributeValueHandler;
use Modules\Catalog\Application\Command\AddBrand\AddBrand;
use Modules\Catalog\Application\Command\AddBrand\AddBrandHandler;
use Modules\Catalog\Application\Command\AddCategory\AddCategory;
use Modules\Catalog\Application\Command\AddCategory\AddCategoryHandler;
use Modules\Catalog\Application\Command\AddVariant\AddVariant;
use Modules\Catalog\Application\Command\AddVariant\AddVariantHandler;
use Modules\Catalog\Application\Command\AddVariantAttribute\AddVariantAttribute;
use Modules\Catalog\Application\Command\AddVariantAttribute\AddVariantAttributeHandler;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Catalog\Application\Command\CreateProduct\CreateProduct;
use Modules\Catalog\Application\Command\CreateProduct\CreateProductHandler;
use Modules\Catalog\Application\Command\EditProductDetails\EditProductDetails;
use Modules\Catalog\Application\Command\EditProductDetails\EditProductDetailsHandler;
use Modules\Catalog\Application\Command\MarkProductReady\MarkProductReady;
use Modules\Catalog\Application\Command\MarkProductReady\MarkProductReadyHandler;
use Modules\Catalog\Application\Command\SetProductGallery\SetProductGallery;
use Modules\Catalog\Application\Command\SetProductGallery\SetProductGalleryHandler;
use Modules\Sync\Application\Command\UnwireStore\UnwireStore;
use Modules\Sync\Application\Command\UnwireStore\UnwireStoreHandler;
use Modules\Sync\Application\Command\WireStore\WireStore;
use Modules\Sync\Application\Command\WireStore\WireStoreHandler;
use Modules\Sync\Application\SyncPermissions as P;
use Modules\Sync\Domain\ValueObject\ProviderValue;
use Modules\Sync\Infrastructure\Provider\OdooAdapter;
use Shared\Application\ActorContext;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Catalog\Support\CatalogFixtures as Cx;
use Tests\Modules\Sync\Support\FakeProvider;

use function Pest\Laravel\seed;

/*
| Sync's screens in a real browser (sync.md §4.1, stage 5's screens step 7). The feature tests beside them
| prove what each page is handed and what the server does with what it sends; these prove a person can use
| them: the Sync Report's status line and Problems, an entry dismissed, Sync Now (Y1); the Reads tab opening a
| read's page, its sections as tabs, and a read that is not there (Y2); the Provider page's form refusing as
| typed, saying the provider's failed test under the form and a setting under its own box, then wiring the
| store (Y3); and Arabic right to left.
|
| No RefreshDatabase, as the other browser tests: the store these wire is the United Arab Emirates (`ae`),
| which no other browser test prices or counts in, unwired before and after each test - and every product
| and code is the clock's own, as the rows stay. The pretend provider stands in for Odoo (FakeProvider).
*/

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
    FakeProvider::bind(fields: ['database']);
    syncBrowserUnwire();
    syncBrowserNoScreenshots();
});

afterEach(function () {
    syncBrowserUnwire();
    syncBrowserNoScreenshots();
});

/** A failed run leaves no screenshots behind. */
function syncBrowserNoScreenshots(): void
{
    $folder = dirname(__DIR__).'/Browser/Screenshots';

    if (is_dir($folder)) {
        foreach (glob($folder.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($folder);
    }
}

/** The store these tests wire, unwired as a Super Admin unwires it - whatever a test, or a failed run, left. */
function syncBrowserUnwire(): void
{
    $store = Fx::storeId('ae');

    if (DB::table('sync.connections')->where('store_id', $store)->exists()) {
        syncBrowserAsSuperAdmin(fn () => app(UnwireStoreHandler::class)->handle(new UnwireStore($store)));
    }
}

/**
 * The work done by a Super Admin, as wiring and unwiring are (sync.md §3) - then the actor the browser's
 * requests use given back, as Fx::asSystem gives it back.
 */
function syncBrowserAsSuperAdmin(callable $work): mixed
{
    $previous = app()->getBindings()[ActorContext::class]['concrete'] ?? null;
    Fx::actAsStaff(Fx::staff(StaffStatus::Active, superAdmin: true));

    try {
        return $work();
    } finally {
        app()->scoped(ActorContext::class, $previous);
        app()->forgetScopedInstances();
    }
}

/** Signed in through the panel's door as this staff member. */
function syncBrowser(string $staffId, string $locale = 'en'): mixed
{
    DB::table('access.staff_users')->where('id', $staffId)->update(['locale' => $locale]);
    $email = (string) DB::table('access.staff_users')->where('id', $staffId)->value('email');

    $page = visit('/admin/sign-in')
        ->type('#email', $email)
        ->type('#password', Fx::STAFF_PASSWORD)
        ->click('button[type="submit"]');

    // A busy machine answers slowly: each step waits for the page it leads to (tests/Pest.php, browserSoon).
    expect(browserSoon($page, "window.location.pathname === '/admin/sign-in/code' && document.querySelector('input[autocomplete=\"one-time-code\"]') !== null"))->toBeTrue();

    $page->type('input[autocomplete="one-time-code"]', RecordingSecurityMessages::installed()->lastCode())
        ->click('button[type="submit"]');

    expect(browserSoon($page, "window.location.pathname === '/admin'"))->toBeTrue();

    return $page;
}

/** Someone who views the report and runs reads in the store, and sees products. */
function syncBrowserReader(string $locale = 'en'): mixed
{
    return syncBrowser(Fx::staffWith([P::REPORT_VIEW, P::READ_RUN, CatalogPermissions::PRODUCT_VIEW], ['ae']), $locale);
}

/**
 * A ready product of its own brand, category and attribute, with one size switched on in the store; its code
 * is the clock's last eight digits behind 6, never another run's.
 *
 * @return array{product: string, size: string, code: string, name: string}
 */
function syncBrowserProduct(): array
{
    $n = substr((string) hrtime(true), -12);
    $ready = Fx::asSystem(function () use ($n): array {
        $brand = app(AddBrandHandler::class)->handle(new AddBrand("ماركة {$n}", "Brand {$n}", 'DISTRIBUTOR'));
        $category = app(AddCategoryHandler::class)->handle(new AddCategory("فئة {$n}", "Category {$n}"));
        $width = app(AddAttributeHandler::class)->handle(new AddAttribute("عرض {$n}", "Width {$n}", 'VARIANT'));
        $value = app(AddAttributeValueHandler::class)->handle(new AddAttributeValue($width, "ستون {$n}", "60 cm {$n}"));
        $product = app(CreateProductHandler::class)->handle(new CreateProduct("رف {$n}", "Shelf {$n}", $brand));
        app(AddVariantAttributeHandler::class)->handle(new AddVariantAttribute($product, $width, []));
        $text = ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Shelf']]]]];
        app(EditProductDetailsHandler::class)->handle(new EditProductDetails($product, "رف {$n}", "Shelf {$n}", $brand, descriptionAr: $text, descriptionEn: $text, categoryId: $category));
        $code = '6'.substr($n, -8);
        $size = app(AddVariantHandler::class)->handle(new AddVariant($product, $code, [$width => $value]));
        app(SetProductGalleryHandler::class)->handle(new SetProductGallery($product, [Cx::media()]));
        app(MarkProductReadyHandler::class)->handle(new MarkProductReady($product));

        return ['product' => $product, 'size' => $size, 'code' => $code, 'name' => "Shelf {$n}"];
    });

    Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId('ae'), $ready['product'], true)));

    return $ready;
}

/**
 * The store wired to the pretend provider, which sends the product's size a price and a stock number that is
 * not whole, and a code we do not have: its first read runs at once (the tests' queue is synchronous) - `read`,
 * known by being the one read wiring added. Never "the store's newest read": a read's times are kept to the
 * second, so the read a test before left (Sync Now) can share this one's second, and the database then answers
 * either (PR #191's CI failure, 2026-10-20).
 *
 * @return array{product: string, size: string, code: string, name: string, unknown: string, read: string}
 */
function syncBrowserWired(): array
{
    $ready = syncBrowserProduct();
    $unknown = '7'.substr((string) hrtime(true), -8);
    FakeProvider::bound()->values = [new ProviderValue($ready['code'], '150.00', '2.5'), new ProviderValue($unknown, '5', 1)];
    $before = DB::table('sync.reads')->where('store_id', Fx::storeId('ae'))->pluck('id')->all();
    syncBrowserAsSuperAdmin(fn () => app(WireStoreHandler::class)->handle(new WireStore(Fx::storeId('ae'), 'ODOO', 'https://odoo.test/api', 'k', 's', ['database' => 'tw'])));
    $added = DB::table('sync.reads')->where('store_id', Fx::storeId('ae'))->whereNotIn('id', $before)->pluck('id')->all();

    expect($added)->toHaveCount(1);

    return [...$ready, 'unknown' => $unknown, 'read' => (string) $added[0]];
}

/** A Discount Skipped entry for the size, naming a category discount deleted since - or, with $named false, none. Its id. */
function syncBrowserDiscountGone(string $size, bool $named = true): int
{
    return (int) DB::table('sync.report_entries')->insertGetId(['store_id' => Fx::storeId('ae'), 'kind' => 'DISCOUNT_SKIPPED', 'code' => null, 'variant_id' => $size, 'sale_id' => null, 'detail' => json_encode($named ? ['discount' => strtolower((string) Str::ulid())] : (object) []), 'opened_at' => now(), 'seen_at' => now()]);
}

it('shows the status line and Problems, dismisses an entry, and queues Sync Now', function () {
    $wired = syncBrowserWired();
    $page = syncBrowserReader();
    $page->navigate('/admin/sync?store=ae', BROWSER_SLOW_PAGE_LOAD);

    expect(browserSoon($page, "document.querySelector('[data-test=\"status-line\"]')?.textContent.includes('Odoo') && document.querySelector('[data-test=\"store-state\"]')?.textContent.includes('Working')"))->toBeTrue()
        ->and(browserSoon($page, "document.querySelector('[data-test=\"last-read\"]')?.textContent.includes('Last read')"))->toBeTrue();

    $entry = (int) DB::table('sync.report_entries')->where('store_id', Fx::storeId('ae'))->where('kind', 'UNKNOWN_CODE')->where('code', $wired['unknown'])->whereNull('closed_at')->value('id');
    $bad = (int) DB::table('sync.report_entries')->where('store_id', Fx::storeId('ae'))->where('kind', 'BAD_VALUE')->where('variant_id', $wired['size'])->whereNull('closed_at')->value('id');

    // Each row with its kind's badge: the provider's code for one we don't have, the product for one of ours.
    expect(browserSoon($page, "document.querySelector('[data-test=\"entry-{$entry}\"] [data-test=\"kind-UNKNOWN_CODE\"]')?.textContent.includes('Unknown Code') && document.querySelector('[data-test=\"entry-{$entry}\"] [data-test=\"what-code\"]')?.textContent === '{$wired['unknown']}'"))->toBeTrue()
        ->and(browserSoon($page, "document.querySelector('[data-test=\"entry-{$bad}\"] [data-test=\"what-product\"]')?.textContent.includes('{$wired['name']}') && document.querySelector('[data-test=\"entry-{$bad}\"]')?.textContent.includes('Not a whole number')"))->toBeTrue()
        ->and(browserSoon($page, "document.querySelector('[data-test=\"tab-problems\"] [data-test=\"problems-count\"]')?.textContent === '2'"))->toBeTrue();

    $page->click("[data-test=\"entry-actions-{$entry}\"]")->click('[data-test="dismiss-entry"]');

    expect(browserSoon($page, "document.querySelector('[data-test=\"confirm-dismiss\"]') !== null"))->toBeTrue();

    $page->click('[data-test="confirm-dismiss"]');

    expect(browserSoon($page, "document.body.textContent.includes('Entry dismissed') && document.querySelector('[data-test=\"entry-{$entry}\"]') === null"))->toBeTrue()
        ->and(DB::table('sync.report_entries')->where('id', $entry)->value('closed_by'))->toStartWith('STAFF:')
        // Its row gone, the focus goes to the next row's ⋯ (the review of step 7), never to the page's start.
        ->and(browserSoon($page, "document.activeElement?.getAttribute('data-test') === 'entry-actions-{$bad}'"))->toBeTrue();

    // Closed meanwhile (a read found it right): the refusal is said inside the dialog, which stays open.
    $page->click("[data-test=\"entry-actions-{$bad}\"]")->click('[data-test="dismiss-entry"]');

    expect(browserSoon($page, "document.querySelector('[data-test=\"confirm-dismiss\"]') !== null"))->toBeTrue();

    DB::table('sync.report_entries')->where('id', $bad)->update(['closed_at' => now(), 'closed_by' => 'READ']);
    $page->click('[data-test="confirm-dismiss"]');

    expect(browserSoon($page, "document.querySelector('[role=\"dialog\"] [data-test=\"form-error\"]')?.textContent.includes('There is no such entry')"))->toBeTrue();

    $page->click('[data-test="modal-cancel"]');

    expect(browserSoon($page, "document.querySelector('[role=\"dialog\"]') === null"))->toBeTrue();

    // One more Now read: the tests' queue is synchronous, so it has run by the time the toast says so.
    $now = fn (): int => DB::table('sync.reads')->where('store_id', Fx::storeId('ae'))->where('kind', 'NOW')->count();
    $before = $now();
    $page->click('[data-test="sync-now"]');

    expect(browserSoon($page, "document.body.textContent.includes('Read queued')"))->toBeTrue()
        ->and($now())->toBe($before + 1);
});

/**
 * What a read's page showed, and what the database holds of the read, when a wait for it failed: so a run that
 * fails says why (the review of PR #191's CI failure, 2026-10-20).
 *
 * @param  array{size: string, read: string}  $about
 */
function syncBrowserReadState(mixed $page, array $about): string
{
    try {
        $shown = $page->script(<<<'JS'
            () => JSON.stringify({
                url: window.location.href,
                h1: document.querySelector('h1')?.textContent ?? null,
                state: document.querySelector('[data-test="read-state"]')?.textContent ?? null,
                header: document.querySelector('[data-test="read-header"]')?.textContent ?? null,
                lines: [...document.querySelectorAll('[data-test^="line-"]')].map((row) => row.getAttribute('data-test') + ' ' + row.textContent),
                fromTo: [...document.querySelectorAll('[data-test="from-to"]')].map((cell) => cell.textContent),
                empty: document.querySelector('[data-test="section-empty"]')?.textContent ?? null,
            })
            JS);
    } catch (Throwable $error) {
        $shown = 'the page could not be read: '.$error->getMessage();
    }

    $read = DB::table('sync.reads')->where('id', $about['read'])->first();
    $newest = DB::table('sync.reads')->where('store_id', Fx::storeId('ae'))->orderByDesc('queued_at')->orderByDesc('id')->limit(3)->get(['id', 'kind', 'state', 'queued_at', 'error'])->all();
    $lines = DB::table('sync.read_lines')->where('read_id', $about['read'])->get(['kind', 'variant_id', 'before', 'after'])->all();
    $price = DB::table('pricing.retail_prices')->where('store_id', Fx::storeId('ae'))->where('variant_id', $about['size'])->first();

    return 'page '.(is_string($shown) ? $shown : json_encode($shown))
        .' | read '.json_encode($read)
        .' | its lines '.json_encode($lines)
        .' | the size '.$about['size'].' priced '.json_encode($price)
        .' | the store\'s newest reads '.json_encode($newest);
}

it('opens a read from the Reads tab, with its header and its sections as tabs; a read not there is not found', function () {
    $wired = syncBrowserWired();
    $read = $wired['read'];
    $about = ['size' => $wired['size'], 'read' => $read];
    $page = syncBrowserReader();
    $page->navigate('/admin/sync?store=ae&tab=reads', BROWSER_SLOW_PAGE_LOAD);

    // Each wait on its own, each failure saying what the page and the database held.
    $wait = function (string $what, string $expression) use ($page, $about): void {
        $held = browserSoon($page, $expression);

        expect($held)->toBeTrue($held ? '' : "{$what}: ".syncBrowserReadState($page, $about));
    };

    $wait('the read listed', "document.querySelector('[data-test=\"read-{$read}\"]')?.textContent.includes('Now')");

    $page->click("[data-test=\"read-{$read}\"] [data-test=\"open-read\"]");

    $wait('the read opened', "window.location.pathname === '/admin/sync/reads/{$read}' && document.querySelector('[data-test=\"read-header\"]') !== null");
    $wait('the read done', "document.querySelector('[data-test=\"read-state\"]')?.textContent.includes('Done')");
    $wait('its title', "document.querySelector('h1')?.textContent.startsWith('Read of')");
    // Prices Changed first: the provider's price, from nothing to 150.00.
    $wait('its price line', "document.querySelector('[data-test=\"line-{$wired['size']}\"] [data-test=\"from-to\"]')?.textContent.includes('150.00')");

    $page->click('[data-test="section-REPORTED"]');

    $wait('its Reported section', "new URL(window.location.href).searchParams.get('section') === 'reported' && document.querySelectorAll('[data-test=\"reported-row\"]').length === 2");
    $wait('no Codes Asked', "document.querySelector('[data-test=\"section-CODES\"]') === null");

    $page->navigate('/admin/sync/reads/01jbzzzzzzzzzzzzzzzzzzzzzz', BROWSER_SLOW_PAGE_LOAD);

    // The panel's own not-found page (frontend.md §2.6): the error's sentence, in the panel's frame, the way back Home.
    expect(browserSoon($page, "document.querySelector('[data-test=\"error-sentence\"]')?.textContent === \"This read isn't available.\" && document.querySelector('h1')?.textContent === 'Not found'"))->toBeTrue()
        ->and(browserSoon($page, "document.querySelector('[data-slot=\"sidebar\"]') !== null && document.querySelector('[data-slot=\"breadcrumb\"] a[href=\"/admin/sync\"]') !== null"))->toBeTrue();

    $page->click('[data-test="error-back"]');

    expect(browserSoon($page, "window.location.pathname === '/admin'"))->toBeTrue();
});

it('says a skipped discount deleted since as Discount removed, with no link (Q37), and nothing of one for an entry naming none', function () {
    $wired = syncBrowserWired();
    $gone = syncBrowserDiscountGone($wired['size']);
    // A size the store no longer has, so the two entries never share one.
    $unnamed = syncBrowserDiscountGone(strtolower((string) Str::ulid()), named: false);
    $page = syncBrowserReader();
    $page->navigate('/admin/sync?store=ae&kind=discount_skipped', BROWSER_SLOW_PAGE_LOAD);

    expect(browserSoon($page, "document.querySelector('[data-test=\"entry-{$gone}\"] [data-test=\"discount-removed\"]')?.textContent === 'Discount removed' && document.querySelector('[data-test=\"discount-link\"]') === null"))->toBeTrue()
        ->and(browserSoon($page, "document.querySelector('[data-test=\"entry-{$unnamed}\"]')?.textContent.includes('Past the ceiling') && document.querySelector('[data-test=\"entry-{$unnamed}\"] [data-test=\"discount-removed\"]') === null"))->toBeTrue();
});

it('draws the Provider form with no "being built" note now that Odoo\'s adapter is built (Q38)', function () {
    // Odoo's own adapter in place of the pretend one: it says it is ready.
    app()->forgetInstance(OdooAdapter::class);
    $page = syncBrowser(Fx::staff(StaffStatus::Active, superAdmin: true));
    $page->navigate('/admin/sync/provider?store=ae', BROWSER_SLOW_PAGE_LOAD);

    expect(browserSoon($page, "document.querySelector('[data-test=\"connection-form\"]') !== null && document.getElementById('provider-address') !== null"))->toBeTrue()
        ->and(browserSoon($page, "document.querySelector('[data-test=\"not-ready\"]') === null && ! document.body.innerText.includes('being built')"))->toBeTrue()
        // Odoo asks for nothing beside the address, the key and the secret (§2.4).
        ->and(browserSoon($page, "document.querySelector('[id^=\"provider-setting-\"]') === null"))->toBeTrue();
});

it('refuses the Provider form as typed, says the failed test under the form and a setting under its own box, then wires the store', function () {
    $page = syncBrowser(Fx::staff(StaffStatus::Active, superAdmin: true));
    $page->navigate('/admin/sync/provider?store=ae', BROWSER_SLOW_PAGE_LOAD);

    expect(browserSoon($page, "document.querySelector('[data-test=\"connection-form\"]') !== null && document.getElementById('provider-setting-database') !== null"))->toBeTrue()
        // The sidebar lights the Provider alone, not the Sync Report at /admin/sync too; and no trail puts it under the report.
        ->and(browserSoon($page, "[...document.querySelectorAll('[data-sidebar=\"menu-sub-button\"][data-active=\"true\"]')].map((each) => each.textContent).join('|') === 'Provider'"))->toBeTrue()
        ->and(browserSoon($page, "document.querySelector('[data-slot=\"breadcrumb\"] a') === null"))->toBeTrue();

    // As typed: an address that is not HTTPS, said under its box.
    $page->type('#provider-address', 'http://odoo.test');

    expect(browserSoon($page, "document.getElementById('provider-address-error')?.textContent === 'The address must start with https://.'"))->toBeTrue();

    $page->type('#provider-address', 'https://odoo.test/api')->type('#provider-setting-database', 'tw');

    // Save with no key: every empty box says so, nothing sent.
    $page->click('[data-test="connection-submit"]');

    expect(browserSoon($page, "document.getElementById('provider-key-error')?.textContent === 'Key is required.'"))->toBeTrue()
        ->and(DB::table('sync.connections')->where('store_id', Fx::storeId('ae'))->exists())->toBeFalse();

    // The provider's test fails: its reason under the form, nothing saved, the key and the secret never kept.
    FakeProvider::bound()->failure = 'The provider answered 503.';
    $page->type('#provider-key', 'k')->type('#provider-secret', 's')->click('[data-test="connection-submit"]');

    expect(browserSoon($page, "document.querySelector('[data-test=\"connection-form\"] [data-test=\"form-error\"]')?.textContent.includes('Failed to read the provider: The provider answered 503.')"))->toBeTrue()
        ->and(browserSoon($page, "document.getElementById('provider-key')?.value === ''"))->toBeTrue()
        ->and(DB::table('sync.connections')->where('store_id', Fx::storeId('ae'))->exists())->toBeFalse();

    // The provider asks for a setting the form did not have: refused under that setting's own box (Q34).
    FakeProvider::bound()->failure = null;
    FakeProvider::bound()->fields = ['database', 'company'];
    $page->type('#provider-key', 'k')->type('#provider-secret', 's')->click('[data-test="connection-submit"]');

    expect(browserSoon($page, "document.getElementById('provider-setting-company-error')?.textContent.includes('company')"))->toBeTrue()
        ->and(browserSoon($page, "document.querySelector('[data-test=\"connection-form\"] [data-test=\"form-error\"]') === null"))->toBeTrue();

    $page->type('#provider-setting-company', 'acme')->type('#provider-key', 'k')->type('#provider-secret', 's')->click('[data-test="connection-submit"]');

    expect(browserSoon($page, "document.body.textContent.includes('Store wired') && document.querySelector('[data-test=\"wired\"]') !== null"))->toBeTrue()
        ->and(browserSoon($page, "document.querySelector('[data-test=\"notice-address\"]')?.textContent.endsWith('/sync/notify/".Fx::storeId('ae')."')"))->toBeTrue()
        ->and(DB::table('sync.connections')->where('store_id', Fx::storeId('ae'))->exists())->toBeTrue();

    // Change Connection refused, then Cancel: the old refusal never comes back (the review of step 7).
    FakeProvider::bound()->failure = 'The provider answered 503.';
    $page->click('[data-test="change-connection"]');

    expect(browserSoon($page, "document.querySelector('[data-test=\"connection-cancel\"]') !== null"))->toBeTrue();

    $page->click('[data-test="connection-submit"]');

    expect(browserSoon($page, "document.querySelector('[data-test=\"connection-form\"] [data-test=\"form-error\"]')?.textContent.includes('The provider answered 503.')"))->toBeTrue();

    $page->click('[data-test="connection-cancel"]');

    expect(browserSoon($page, "document.querySelector('[data-test=\"wired\"]') !== null && document.querySelector('[data-test=\"form-error\"]') === null"))->toBeTrue();

    $page->click('[data-test="change-connection"]');

    expect(browserSoon($page, "document.querySelector('[data-test=\"connection-form\"]') !== null && document.querySelector('[data-test=\"form-error\"]') === null"))->toBeTrue();
});

it('refuses a new address with the key left empty, under the Key box, and tests nothing (owner, 2026-10-20)', function () {
    syncBrowserAsSuperAdmin(fn () => app(WireStoreHandler::class)->handle(new WireStore(Fx::storeId('ae'), 'ODOO', 'https://odoo.test/api', 'k', 's', ['database' => 'tw'])));
    $tested = count(FakeProvider::bound()->tested);
    $page = syncBrowser(Fx::staff(StaffStatus::Active, superAdmin: true));
    $page->navigate('/admin/sync/provider?store=ae', BROWSER_SLOW_PAGE_LOAD);

    expect(browserSoon($page, "document.querySelector('[data-test=\"change-connection\"]') !== null"))->toBeTrue();

    $page->click('[data-test="change-connection"]');

    expect(browserSoon($page, "document.getElementById('provider-address') !== null"))->toBeTrue();

    $page->type('#provider-address', 'https://odoo2.test/api')->click('[data-test="connection-submit"]');

    expect(browserSoon($page, "document.getElementById('provider-key-error')?.textContent === 'Type the key again: the address changed.'"))->toBeTrue()
        ->and(count(FakeProvider::bound()->tested))->toBe($tested)
        ->and(DB::table('sync.connections')->where('store_id', Fx::storeId('ae'))->value('address'))->toBe('https://odoo.test/api');
});

it('draws the Sync Report right to left in Arabic, its digits Latin', function () {
    $wired = syncBrowserWired();
    syncBrowserDiscountGone($wired['size']);
    $page = syncBrowserReader('ar');
    $page->navigate('/admin/sync?store=ae', BROWSER_SLOW_PAGE_LOAD);

    expect(browserSoon($page, "document.documentElement.dir === 'rtl'"))->toBeTrue()
        ->and(browserSoon($page, "document.querySelector('h1')?.textContent.includes('تقرير المزامنة')"))->toBeTrue()
        ->and(browserSoon($page, "document.querySelector('[data-test=\"store-state\"]')?.textContent.includes('يعمل')"))->toBeTrue()
        ->and(browserSoon($page, "document.querySelector('[data-test=\"discount-removed\"]')?.textContent === 'الخصم محذوف'"))->toBeTrue()
        ->and(browserSoon($page, "!/[\u{0660}-\u{0669}]/.test(document.body.textContent ?? '')"))->toBeTrue();

    // A read that is not there: the panel's not-found page in Arabic, right to left.
    $page->navigate('/admin/sync/reads/01jbzzzzzzzzzzzzzzzzzzzzzz', BROWSER_SLOW_PAGE_LOAD);

    expect(browserSoon($page, "document.documentElement.dir === 'rtl' && document.querySelector('[data-test=\"error-sentence\"]')?.textContent === 'هذه القراءة غير متاحة.'"))->toBeTrue()
        ->and(browserSoon($page, "document.querySelector('[data-test=\"error-back\"]')?.textContent === 'العودة إلى الرئيسية'"))->toBeTrue();
});
