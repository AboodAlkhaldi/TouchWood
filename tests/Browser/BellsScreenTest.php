<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Modules\Platform\Public\PlatformPermissions as Platform;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Ops\Support\OpsFixtures as Ox;

use function Pest\Laravel\seed;

/*
| O1 and O2, the two bells in a real browser (ops.md §4.15): the number with the page, the list on pressing,
| a row marked, Mark All as Read, View All and its full page with Show More, what a failure says, in both
| languages, and a phone.
*/

// Deliberately no RefreshDatabase: the suite serves the application in this process, and a served
// request opens its own connection, so the two would deadlock.

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

/** The number on the bell, whole: null when none is drawn. */
function bellsScreenNumber(mixed $page): ?string
{
    $number = $page->script("document.querySelector('[data-test=\"bell-number\"]')?.textContent.trim() ?? null");

    return is_string($number) ? $number : null;
}

function bellsScreenStaffIn(string $staffId): mixed
{
    $page = visit('/admin/sign-in')
        ->type('#email', (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'))
        ->type('#password', Fx::STAFF_PASSWORD)
        ->click('button[type="submit"]')
        ->assertPathIs('/admin/sign-in/code')
        ->type('input[autocomplete="one-time-code"]', RecordingSecurityMessages::installed()->lastCode())
        ->click('button[type="submit"]');

    expect(signedInToPanel($page))->toBeTrue();

    return $page;
}

function bellsScreenCustomerIn(string $email): mixed
{
    $page = visit('/sa/en/sign-in')
        ->type('#email', $email)
        ->type('#password', Fx::CUSTOMER_PASSWORD)
        ->click('button[type="submit"]');

    expect(browserSoon($page, "document.querySelector('[data-test=\"bell\"]') !== null"))->toBeTrue();

    return $page;
}

it('shows staff their number, marks a row and all of them, opens the full page in both languages, and fits a phone', function () {
    $staffId = Fx::staffWith([Platform::AUDIT_VIEW], ['sa', 'ae']);

    foreach (range(1, 22) as $ignored) {
        Ox::staffEntry($staffId);
    }

    // No link on these: a press stays on the page and opens the row. One long word, as a pasted address would be.
    DB::table('ops.inbox_entries')->where('staff_id', $staffId)->update(['link' => null, 'line_en' => str_repeat('a', 400)]);

    $page = bellsScreenStaffIn($staffId);

    // The number comes with the page, and the bell says it in words.
    expect(bellsScreenNumber($page))->toBe('22')
        ->and($page->script("document.querySelector('[data-test=\"bell\"]').getAttribute('aria-label')"))->toBe('Notifications, 22 unread');
    $page->assertNoJavaScriptErrors();

    // Pressing it reads the newest 20; each names its store, for someone with two. The menu has a name.
    $page->click('[data-test="bell"]');
    expect(browserSoon($page, "document.querySelectorAll('[data-test=\"bell-menu\"] [data-test=\"bell-row\"]').length === 20"))->toBeTrue()
        ->and($page->script("document.querySelector('[data-test=\"bell-menu\"] [data-test=\"bell-row-store\"]').textContent"))->toContain('Saudi')
        ->and($page->script("document.getElementById(document.querySelector('[data-test=\"bell-menu\"]').getAttribute('aria-labelledby')).textContent"))->toBe('Notifications');

    // A row with no link stays, opens, and takes one off the number.
    $page->click('[data-test="bell-menu"] [data-test="bell-row"]:first-child button');
    expect(browserSoon($page, "document.querySelector('[data-test=\"bell-number\"]').textContent.trim() === '21'"))->toBeTrue()
        ->and(browserSoon($page, "document.querySelector('[data-test=\"bell-menu\"] [data-test=\"bell-row\"]').dataset.read === 'true'"))->toBeTrue()
        ->and(browserSoon($page, "document.querySelector('[data-test=\"bell-menu\"] [data-test=\"bell-row\"] button').getAttribute('aria-expanded') === 'true'"))->toBeTrue()
        ->and(DB::table('ops.inbox_entries')->where('staff_id', $staffId)->whereNotNull('read_at')->count())->toBe(1);

    // The button shuts it, and gives focus back.
    $page->click('[data-test="bell"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"bell-menu\"]') === null && document.activeElement === document.querySelector('[data-test=\"bell\"]')"))->toBeTrue();
    $page->click('[data-test="bell"]');
    expect(browserSoon($page, "document.querySelectorAll('[data-test=\"bell-menu\"] [data-test=\"bell-row\"]').length === 20"))->toBeTrue();

    // View All: the full page, the trail, and opening it marked nothing.
    $page->click('[data-test="bell-view-all"]');
    expect(browserSoon($page, "document.querySelectorAll('[data-test=\"bell-page\"] [data-test=\"bell-row\"]').length === 20"))->toBeTrue();
    $page->assertPathIs('/admin/notifications')->assertSeeIn('[data-slot="breadcrumb"]', 'Home')->assertNoJavaScriptErrors();
    expect(DB::table('ops.inbox_entries')->where('staff_id', $staffId)->whereNotNull('read_at')->count())->toBe(1);

    // Show More adds the two older rows, keeping the one marked.
    $page->click('[data-test="more"]');
    expect(browserSoon($page, "document.querySelectorAll('[data-test=\"bell-page\"] [data-test=\"bell-row\"]').length === 22"))->toBeTrue()
        ->and(DB::table('ops.inbox_entries')->where('staff_id', $staffId)->whereNotNull('read_at')->count())->toBe(1);

    // A row marked on the page takes one off the bell, and the rows Show More added stay.
    $page->click('[data-test="bell-page"] [data-test="bell-row"]:nth-child(2) button');
    expect(browserSoon($page, "document.querySelector('[data-test=\"bell-number\"]').textContent.trim() === '20'"))->toBeTrue()
        ->and(browserSoon($page, "document.querySelector('[data-test=\"bell-page\"] [data-test=\"bell-row\"]:nth-child(2)').dataset.read === 'true'"))->toBeTrue()
        ->and($page->script("document.querySelectorAll('[data-test=\"bell-page\"] [data-test=\"bell-row\"]').length"))->toBe(22);

    // Arabic, right to left, in Latin digits.
    expect(browserSoon($page, "document.querySelector('[data-test=\"language\"]') !== null"))->toBeTrue();
    $page->click('[data-test="language"]');
    expect(browserSoon($page, "document.documentElement.getAttribute('dir') === 'rtl' && document.querySelector('[data-test=\"bell\"]').getAttribute('aria-label') === 'الإشعارات غير المقروءة: 20'"))->toBeTrue()
        ->and(bellsScreenNumber($page))->toBe('20');

    // A phone, the long word included: nothing sideways, the bell's menu inside the screen. The long word's language is
    // English, so the phone reads English here.
    expect(browserSoon($page, "document.querySelector('[data-test=\"language\"]') !== null"))->toBeTrue();
    $page->click('[data-test="language"]');
    expect(browserSoon($page, "document.documentElement.getAttribute('dir') === 'ltr'"))->toBeTrue();
    $page->resize(375, 812);
    $page->navigate('/admin/notifications');
    expect(browserSoon($page, "document.querySelector('[data-test=\"bell-page\"]') !== null"))->toBeTrue()
        ->and(browserSoon($page, 'document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
    $page->click('[data-test="bell"]');
    expect(browserSoon($page, "document.querySelectorAll('[data-test=\"bell-menu\"] [data-test=\"bell-row\"]').length === 20"))->toBeTrue()
        ->and(browserSoon($page, "(() => { const box = document.querySelector('[data-test=\"bell-menu\"]').getBoundingClientRect(); return box.left >= 0 && box.right <= window.innerWidth; })()"))->toBeTrue()
        ->and($page->script("(() => { const row = document.querySelector('[data-test=\"bell-menu\"] [data-test=\"bell-row\"]').getBoundingClientRect(); const box = document.querySelector('[data-test=\"bell-menu\"]').getBoundingClientRect(); return row.right <= box.right; })()"))->toBeTrue();

    // Mark All as Read marks every entry, not only the 20 shown, goes away, and leaves focus in the menu.
    $page->click('[data-test="bell-mark-all"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"bell-number\"]') === null && document.querySelector('[data-test=\"bell-mark-all\"]') === null"))->toBeTrue()
        ->and(DB::table('ops.inbox_entries')->where('staff_id', $staffId)->whereNull('read_at')->count())->toBe(0)
        ->and(browserSoon($page, "[...document.querySelectorAll('[data-test=\"bell-menu\"] [data-test=\"bell-row\"]')].every((row) => row.dataset.read === 'true')"))->toBeTrue()
        ->and($page->script('document.activeElement.tagName'))->toBe('H2');
    $page->assertNoJavaScriptErrors();

    // Coming back to the tab: sooner than a minute asks nothing; after a minute it asks once and shows a new entry's number.
    Ox::staffEntry($staffId);
    $reads = "performance.getEntriesByType('resource').filter((entry) => entry.name.includes('/notifications/unread')).length";
    $hide = "Object.defineProperty(document, 'hidden', { configurable: true, get: () => true }); document.dispatchEvent(new Event('visibilitychange'));";
    $show = "Object.defineProperty(document, 'hidden', { configurable: true, get: () => false }); document.dispatchEvent(new Event('visibilitychange'));";
    $page->script("(() => { {$hide} {$show} })()");
    $page->script("(() => { const real = Date.now; {$hide} Date.now = () => real() + 61000; {$show} Date.now = real; })()");
    expect(browserSoon($page, "document.querySelector('[data-test=\"bell-number\"]')?.textContent.trim() === '1'"))->toBeTrue()
        ->and($page->script($reads))->toBe(1);
});

it('goes to a staff row\'s link, opening the bell after going Back corrects the number, and a tab loaded later opens by its address', function () {
    $staffId = Fx::staffWith([Platform::AUDIT_VIEW], ['sa']);
    Ox::staffEntry($staffId);
    Ox::staffEntry($staffId);
    DB::table('ops.inbox_entries')->where('staff_id', $staffId)->update(['link' => '/admin/account']);

    $page = bellsScreenStaffIn($staffId);
    $page->navigate('/admin/notifications');
    expect(browserSoon($page, "document.querySelectorAll('[data-test=\"bell-page\"] [data-test=\"bell-row\"]').length === 2"))->toBeTrue()
        ->and(bellsScreenNumber($page))->toBe('2')
        // One store: no store's name on a row.
        ->and($page->script("document.querySelector('[data-test=\"bell-row-store\"]') === null"))->toBeTrue();

    $page->click('[data-test="bell-page"] [data-test="bell-row"]:first-child a');
    expect(browserSoon($page, "location.pathname === '/admin/account'"))->toBeTrue()
        ->and(browserSoon($page, "document.querySelector('[data-test=\"bell-number\"]')?.textContent.trim() === '1'"))->toBeTrue();

    $page->script('(() => { history.back(); })()');
    expect(browserSoon($page, "location.pathname === '/admin/notifications' && document.querySelector('[data-test=\"bell-page\"]') !== null"))->toBeTrue();

    // Back shows the number it left (useBellNumber says why); opening the bell reads it again.
    $page->click('[data-test="bell"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"bell-number\"]')?.textContent.trim() === '1'"))->toBeTrue();
    $page->click('[data-test="bell"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"bell-menu\"]') === null"))->toBeTrue();

    // Sessions loads when wanted (the 60 KB limit): opened by its address, it still draws, with no error.
    $page->navigate('/admin/account?tab=sessions');
    expect(browserSoon($page, "document.querySelector('[role=\"tabpanel\"][data-state=\"active\"]')?.textContent.trim().length > 0"))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

it('brings back every row, never the last page alone, when a row is marked after Show More and the list is gone back to', function () {
    $staffId = Fx::staffWith([Platform::AUDIT_VIEW], ['sa']);

    foreach (range(1, 22) as $ignored) {
        Ox::staffEntry($staffId);
    }

    DB::table('ops.inbox_entries')->where('staff_id', $staffId)->update(['link' => '/admin/account']);

    $page = bellsScreenStaffIn($staffId);
    $page->navigate('/admin/notifications');
    expect(browserSoon($page, "document.querySelectorAll('[data-test=\"bell-page\"] [data-test=\"bell-row\"]').length === 20"))->toBeTrue();
    $newest = (string) DB::table('ops.inbox_entries')->where('staff_id', $staffId)->orderByDesc('id')->value('id');

    $page->click('[data-test="more"]');
    expect(browserSoon($page, "document.querySelectorAll('[data-test=\"bell-page\"] [data-test=\"bell-row\"]').length === 22"))->toBeTrue();

    // A row is marked, then Back: never the last page alone.
    $page->click('[data-test="bell-page"] [data-test="bell-row"]:first-child a');
    expect(browserSoon($page, "location.pathname === '/admin/account'"))->toBeTrue()
        ->and(DB::table('ops.inbox_entries')->where('id', $newest)->whereNotNull('read_at')->count())->toBe(1);

    $page->script('(() => { history.back(); })()');
    expect(browserSoon($page, "location.pathname === '/admin/notifications' && document.querySelector('[data-test=\"bell-page\"] [data-test=\"bell-row\"]') !== null"))->toBeTrue();
    expect($page->script("document.querySelectorAll('[data-test=\"bell-page\"] [data-test=\"bell-row\"]').length"))->toBe(22);
    $page->assertNoJavaScriptErrors();
});

it('shows a customer their bell before the cart, goes to a row\'s link, and lists the page in the account', function () {
    $email = 'bell-'.strtolower(str()->random(8)).'@example.test';
    $customerId = Fx::customer($email);
    $linked = Ox::customerEntry($customerId, 'sa');
    DB::table('ops.inbox_entries')->where('id', $linked)->update(['link' => '/sa/en/account/notifications']);
    Ox::customerEntry($customerId, 'sa');

    $page = bellsScreenCustomerIn($email);
    expect(bellsScreenNumber($page))->toBe('2');
    $page->assertNoJavaScriptErrors();

    // Before the cart, as the spec draws it.
    expect($page->script("!!(document.querySelector('[data-test=\"bell\"]').compareDocumentPosition(document.querySelector('[data-test=\"header-cart\"]')) & Node.DOCUMENT_POSITION_FOLLOWING)"))->toBeTrue();

    // No store's name on a customer's rows: their bell is the store being shopped.
    $page->click('[data-test="bell"]');
    expect(browserSoon($page, "document.querySelectorAll('[data-test=\"bell-menu\"] [data-test=\"bell-row\"]').length === 2"))->toBeTrue()
        ->and($page->script("document.querySelector('[data-test=\"bell-row-store\"]') === null"))->toBeTrue();

    // A row with a link marks it and goes there.
    $page->click('[data-test="bell-menu"] [data-test="bell-row"] a[href="/sa/en/account/notifications"]');
    expect(browserSoon($page, "location.pathname === '/sa/en/account/notifications' && document.querySelector('[data-test=\"bell-page\"]') !== null"))->toBeTrue()
        ->and(browserSoon($page, "document.querySelector('[data-test=\"bell-number\"]')?.textContent.trim() === '1'"))->toBeTrue()
        ->and(DB::table('ops.inbox_entries')->where('id', $linked)->whereNotNull('read_at')->count())->toBe(1);

    $page->assertVisible('[data-test="account-page-ops.notifications"]')->assertNoJavaScriptErrors();

    // Arabic, on a phone: its words, Latin digits, nothing sideways, the bell's menu inside the screen.
    $page->resize(375, 812);
    $page->navigate('/sa/ar/account/notifications');
    expect(browserSoon($page, "document.documentElement.getAttribute('dir') === 'rtl' && document.querySelector('[data-test=\"bell-page\"]') !== null"))->toBeTrue()
        ->and($page->script("document.querySelector('h1').textContent"))->toBe('الإشعارات')
        ->and(bellsScreenNumber($page))->toBe('1')
        ->and(browserSoon($page, 'document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
    $page->click('[data-test="bell"]');
    expect(browserSoon($page, "document.querySelectorAll('[data-test=\"bell-menu\"] [data-test=\"bell-row\"]').length === 2"))->toBeTrue()
        ->and(browserSoon($page, "(() => { const box = document.querySelector('[data-test=\"bell-menu\"]').getBoundingClientRect(); return box.left >= 0 && box.right <= window.innerWidth; })()"))->toBeTrue();
    $page->click('[data-test="bell"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"bell-menu\"]') === null"))->toBeTrue();

    // Mark All as Read on the full page, and focus stays on the page.
    $page->click('[data-test="bell-page-mark-all"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"bell-number\"]') === null && document.querySelector('[data-test=\"bell-page-mark-all\"]') === null"))->toBeTrue()
        ->and(DB::table('ops.inbox_entries')->where('customer_id', $customerId)->whereNull('read_at')->count())->toBe(0)
        ->and($page->script('document.activeElement.dataset.test'))->toBe('bell-page');
    $page->assertNoJavaScriptErrors();
});

it('says what failed and offers it again, a failed mark still goes, and a number above 99 reads 99+', function () {
    $email = 'bell-'.strtolower(str()->random(8)).'@example.test';
    $customerId = Fx::customer($email);

    foreach (range(1, 100) as $ignored) {
        Ox::customerEntry($customerId, 'sa');
    }

    $page = bellsScreenCustomerIn($email);
    expect(bellsScreenNumber($page))->toBe('99+')
        ->and($page->script("document.querySelector('[data-test=\"bell\"]').getAttribute('aria-label')"))->toBe('Notifications, 100 unread');

    // The list can't be read: said, with Try Again, and the number unmoved.
    // Each inside a function that answers nothing: a script answering with a function has it called.
    $page->script("(() => { window.realFetch = window.fetch; window.fetch = () => Promise.reject(new Error('down')); })()");
    $page->click('[data-test="bell"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"bell-failed\"]') !== null"))->toBeTrue()
        ->and(bellsScreenNumber($page))->toBe('99+');
    $page->script('(() => { window.fetch = window.realFetch; })()');
    $page->click('[data-test="bell-retry"]');
    expect(browserSoon($page, "document.querySelectorAll('[data-test=\"bell-menu\"] [data-test=\"bell-row\"]').length === 20"))->toBeTrue();

    // Mark All fails: said, and gone on the next opening.
    $page->script("(() => { window.fetch = (path, options) => (options?.method === 'POST' ? Promise.reject(new Error('down')) : window.realFetch(path, options)); })()");
    $page->click('[data-test="bell-mark-all"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"bell-mark-failed\"]') !== null"))->toBeTrue()
        ->and(DB::table('ops.inbox_entries')->where('customer_id', $customerId)->whereNull('read_at')->count())->toBe(100);
    $page->click('[data-test="bell"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"bell-menu\"]') === null"))->toBeTrue();
    $page->click('[data-test="bell"]');
    expect(browserSoon($page, "document.querySelectorAll('[data-test=\"bell-menu\"] [data-test=\"bell-row\"]').length === 20 && document.querySelector('[data-test=\"bell-mark-failed\"]') === null"))->toBeTrue();

    // A row's mark fails: it still goes where the row points, still unread.
    $page->click('[data-test="bell-menu"] [data-test="bell-row"]:first-child a');
    expect(browserSoon($page, "location.pathname === '/sa/en/account/orders/SA-0001'"))->toBeTrue()
        ->and(DB::table('ops.inbox_entries')->where('customer_id', $customerId)->whereNull('read_at')->count())->toBe(100);
});

it('says when there is nothing yet, in the bell and on the full page', function () {
    $email = 'bell-'.strtolower(str()->random(8)).'@example.test';
    Fx::customer($email);

    $page = bellsScreenCustomerIn($email);
    expect(bellsScreenNumber($page))->toBeNull()
        ->and($page->script("document.querySelector('[data-test=\"bell\"]').getAttribute('aria-label')"))->toBe('Notifications');

    $page->click('[data-test="bell"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"bell-empty\"]') !== null && document.querySelector('[data-test=\"bell-mark-all\"]') === null"))->toBeTrue();

    $page->click('[data-test="bell-view-all"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"bell-page-empty\"]') !== null && document.querySelector('[data-test=\"bell-page-mark-all\"]') === null"))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});
