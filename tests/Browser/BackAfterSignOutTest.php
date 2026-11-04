<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Ops\Support\OpsFixtures as Ox;
use Tests\Modules\Promotions\Support\PromotionsFixtures as Px;

use function Pest\Laravel\seed;

/*
| Going Back once signed out (owner, 2026-11-03; frontend.md §1.12): Inertia keeps each page's props in the
| browser's history entry, so `Cache-Control: no-store` alone leaves them there, and Back draws them with no
| request. Each test watches from before Back, so a row that only flashes fails it too. It reads what is drawn
| (innerText): the document's own first-load script keeps that load's JSON until the next whole page load, which
| Back has nothing to do with (frontend.md §1.12 says so).
|
| Deliberately no RefreshDatabase, as the other browser tests.
*/

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

dataset('back after sign-out widths', [
    'desktop' => [1280, 800],
    'phone' => [375, 812],
]);

/** Once the first page has loaded: the first of a run can take a minute on a cold machine, and a resize during it timed out. */
function backResize(mixed $page, int $width, int $height): void
{
    expect(browserSoon($page, "document.readyState === 'complete'", 90))->toBeTrue();
    $page->resize($width, $height);
}

function backStaffIn(int $width, int $height): mixed
{
    $page = visit('/admin/sign-in');
    backResize($page, $width, $height);
    $page->type('#email', (string) DB::table('access.staff_users')->where('id', Fx::staff(superAdmin: true))->value('email'))
        ->type('#password', Fx::STAFF_PASSWORD)
        ->click('button[type="submit"]')
        ->assertPathIs('/admin/sign-in/code')
        ->type('input[autocomplete="one-time-code"]', RecordingSecurityMessages::installed()->lastCode())
        ->click('button[type="submit"]');

    expect(signedInToPanel($page))->toBeTrue();

    return $page;
}

function backStaffOut(mixed $page, int $width): void
{
    // On a phone the person's menu is inside the sidebar's sheet.
    if ($width < 768) {
        $page->click('[data-sidebar="trigger"]');
    }

    $page->click('[data-test="person-menu"]');
    expect(browserSoon($page, "[...document.querySelectorAll('[role=\"menuitem\"]')].some((item) => item.textContent.includes('Sign Out'))"))->toBeTrue();
    $page->script("(() => { [...document.querySelectorAll('[role=\"menuitem\"]')].find((item) => item.textContent.includes('Sign Out')).click(); })()");
    expect(browserSoon($page, "location.pathname === '/admin/sign-in'"))->toBeTrue();
}

function backCustomerIn(string $email, int $width, int $height): mixed
{
    // A page seen as a guest first: its entry stays in the history under the signed-in ones.
    $page = visit('/sa/en');
    backResize($page, $width, $height);
    $page->navigate('/sa/en/sign-in', BROWSER_PAGE_LOAD);
    $page->type('#email', $email)->type('#password', Fx::CUSTOMER_PASSWORD)->click('button[type="submit"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"bell\"]') !== null"))->toBeTrue();

    return $page;
}

function backCustomerOut(mixed $page): void
{
    $page->click('[data-test="shopper-menu"]')->click('[data-test="sign-out"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"bell\"]') === null && document.querySelector('[data-test=\"shopper-menu\"]') === null"))->toBeTrue();
}

/**
 * From here on, once signed out, the page itself notes whether the text or a signed-in frame is ever drawn,
 * however briefly, and when Back has landed.
 */
function backWatch(mixed $page, string $private): void
{
    $page->script(<<<JS
        (() => {
            window.backSeen = false;
            window.backLanded = false;
            const look = () => {
                if (document.body.innerText.includes('{$private}') || document.querySelector('[data-test="shopper-menu"], [data-test="person-menu"]') !== null) {
                    window.backSeen = true;
                }
            };
            new MutationObserver(look).observe(document.body, { subtree: true, childList: true, characterData: true });
            window.addEventListener('popstate', () => { window.backLanded = true; });
        })()
        JS);
}

/**
 * Back, then what the person is looking at once it has settled: the private text must never have been drawn.
 *
 * @param  string|null  $path  where the address ends; null when the server's answer is the page already drawn, which
 *                             Inertia does not write to the history, so the address stays at the entry Back landed on
 */
function backAndExpectGone(mixed $page, string $private, ?string $path): void
{
    $page->script('(() => { window.backLanded = false; history.back(); })()');

    // Ends as soon as either is true: the rows are drawn (the bug), or the server has sent them away.
    $ended = $path === null ? 'true' : "location.pathname === '{$path}'";
    browserSoon($page, "window.backSeen === true || (window.backLanded === true && {$ended})", 5);
    usleep(800_000);

    $where = (string) $page->script('location.pathname');
    expect($page->script('window.backLanded === true'))->toBeTrue('Back never landed')
        ->and($page->script('window.backSeen === true'))->toBeFalse("Back drew the private text, at {$where}")
        ->and($page->script("document.body.innerText.includes('{$private}')"))->toBeFalse("the private text is on the page, at {$where}");

    if ($path !== null) {
        expect($where)->toBe($path);
    }

    $page->assertNoJavaScriptErrors();
}

/**
 * A Back that crosses into another document: nothing watches a page not yet there, so what it ends on is read. The old
 * document is marked, and the mark going is what says Back has landed: the address alone may already read the same.
 */
function backAcrossAndExpectGone(mixed $page, string $private, string $path, int $steps = -1): void
{
    $page->script("(() => { window.backOldDocument = true; history.go({$steps}); })()");
    expect(browserSoon($page, "window.backOldDocument === undefined && location.pathname === '{$path}'"))->toBeTrue("Back ended at {$page->script('location.pathname')}, still in the old document: {$page->script('window.backOldDocument === true')}");
    usleep(500_000);

    expect($page->script("document.body.innerText.includes('{$private}')"))->toBeFalse('the private text is on the page')
        ->and($page->script('document.querySelector(\'[data-test="shopper-menu"], [data-test="person-menu"]\') === null'))->toBeTrue('a signed-in frame is drawn');
    $page->assertNoJavaScriptErrors();
}

function backCouponsOpen(mixed $page, string $code): void
{
    $page->navigate("/admin/coupons?store=sa&code={$code}", BROWSER_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"coupon-{$code}\"]') !== null"))->toBeTrue();
}

function backCode(): string
{
    $code = 'BK'.substr((string) hrtime(true), -9);
    Px::coupon($code);

    return $code;
}

it('shows a customer none of their rows on Back after signing out, nor their name further back', function (int $width, int $height) {
    $private = 'Private '.Str::random(12);
    $email = strtolower((string) Str::ulid()).'@example.test';
    Ox::customerEntry(Fx::customer($email), 'sa', title: $private);

    $page = backCustomerIn($email, $width, $height);
    $page->navigate('/sa/en/account/notifications', BROWSER_PAGE_LOAD);
    expect(browserSoon($page, "document.body.innerText.includes('{$private}')"))->toBeTrue();

    backCustomerOut($page);
    backWatch($page, $private);

    // The page needs someone signed in, so the server sends them to the shop's home, as a guest.
    backAndExpectGone($page, $private, '/sa/en');

    // Further back, across a whole page load: the home page they saw signed in, whose frame had their name, comes
    // back a guest's.
    backAcrossAndExpectGone($page, $private, '/sa/en');

    // And on, within that document, into the page seen as a guest, which was never anyone's.
    backWatch($page, $private);
    backAndExpectGone($page, $private, '/sa/en/sign-in');
})->with('back after sign-out widths');

it('shows staff none of a list\'s rows on Back after signing out', function (int $width, int $height) {
    $code = backCode();
    $page = backStaffIn($width, $height);
    backCouponsOpen($page, $code);

    backStaffOut($page, $width);
    backWatch($page, $code);
    backAndExpectGone($page, $code, '/admin/sign-in');
})->with('back after sign-out widths');

it('shows nothing on a Back that crosses a whole page load after signing out', function () {
    $code = backCode();
    $page = backStaffIn(1280, 800);
    backCouponsOpen($page, $code);
    backStaffOut($page, 1280);

    // A page of the signed-out, loaded whole: Back now starts a new document, and the browser hands it the old entry.
    $page->navigate('/admin/password/forgot', BROWSER_PAGE_LOAD);
    backAcrossAndExpectGone($page, $code, '/admin/sign-in', -2);
});

it('shows nothing on Back after staff sign out everywhere', function () {
    $code = backCode();
    $page = backStaffIn(1280, 800);
    backCouponsOpen($page, $code);

    $page->navigate('/admin/account?tab=sessions', BROWSER_PAGE_LOAD);
    $page->click('[data-test="sign-out-everywhere"]')->click('[data-test="confirm-sign-out-everywhere"]')->assertPathIs('/admin/sign-in');

    backAcrossAndExpectGone($page, $code, '/admin/sign-in', -2);
});

it('shows nothing on Back once staff\'s session ended with no sign-out, from their next click', function () {
    $code = backCode();
    $page = backStaffIn(1280, 800);
    backCouponsOpen($page, $code);

    // The idle limit, a forced sign-out, another device's Sign Out Everywhere: the row is gone, the tab knows nothing.
    DB::table('access.admin_sessions')->delete();
    $page->script("(() => { document.querySelector('[data-test=\"coupon-{$code}\"] a').click(); })()");
    expect(browserSoon($page, "location.pathname === '/admin/sign-in'"))->toBeTrue();

    backWatch($page, $code);
    backAndExpectGone($page, $code, null);
    expect($page->script("document.querySelector('#password') !== null"))->toBeTrue();
});

it('shows nothing on Back once a customer\'s session ended with no sign-out, from their next click', function () {
    $private = 'Private '.Str::random(12);
    $email = strtolower((string) Str::ulid()).'@example.test';
    Ox::customerEntry(Fx::customer($email), 'sa', title: $private);

    $page = backCustomerIn($email, 1280, 800);
    $page->navigate('/sa/en/account/notifications', BROWSER_PAGE_LOAD);
    expect(browserSoon($page, "document.body.innerText.includes('{$private}')"))->toBeTrue();

    DB::table('sessions')->delete();
    $page->script("(() => { document.querySelector('a[href=\"/sa/en\"]').click(); })()");
    expect(browserSoon($page, "location.pathname === '/sa/en' && document.querySelector('[data-test=\"shopper-menu\"]') === null"))->toBeTrue();

    backWatch($page, $private);
    backAndExpectGone($page, $private, null);
    expect($page->script("document.querySelector('a[href$=\"/sa/en/sign-in\"]') !== null"))->toBeTrue();
});

it('sends a tab to the server when a read of the panel\'s says the session is gone, and that drops the key', function () {
    $code = backCode();
    $page = backStaffIn(1280, 800);
    backCouponsOpen($page, $code);
    expect(browserSoon($page, 'sessionStorage.getItem("historyKey") !== null'))->toBeTrue();

    // No click to another page: the bell's read is all the tab asks, and it is answered 401.
    DB::table('access.admin_sessions')->delete();
    $page->click('[data-test="bell"]');
    expect(browserSoon($page, "location.pathname === '/admin/sign-in'"))->toBeTrue()
        ->and(browserSoon($page, 'sessionStorage.getItem("historyKey") === null'))->toBeTrue();

    $page->assertNoJavaScriptErrors();
});

it('still brings a list back on Back while signed in, encrypted in the history, with no request', function (int $width, int $height) {
    $code = backCode();
    $page = backStaffIn($width, $height);
    backCouponsOpen($page, $code);
    expect(browserSoon($page, 'history.state.page instanceof ArrayBuffer'))->toBeTrue();

    $reads = "performance.getEntriesByType('resource').filter((entry) => entry.name.includes('/admin/coupons?')).length";
    $readsBefore = (int) $page->script($reads);
    $page->script("(() => { document.querySelector('[data-test=\"coupon-{$code}\"] a').click(); })()");
    expect(browserSoon($page, "location.pathname.startsWith('/admin/coupons/')"))->toBeTrue();

    $page->script('(() => { history.back(); })()');
    expect(browserSoon($page, "location.pathname === '/admin/coupons' && document.querySelector('[data-test=\"coupon-{$code}\"]') !== null"))->toBeTrue();
    usleep(500_000);
    expect((int) $page->script($reads))->toBe($readsBefore);
    $page->assertNoJavaScriptErrors();
})->with('back after sign-out widths');

it('keeps a guest\'s shop pages out of the encryption, with Back as instant as it was', function () {
    $page = visit('/sa/en');
    expect(browserSoon($page, 'history.state?.page?.component !== undefined'))->toBeTrue()
        ->and($page->script('sessionStorage.getItem("historyKey")'))->toBeNull();

    $page->script("(() => { document.querySelector('a[href$=\"/sa/en/sign-in\"]').click(); })()");
    expect(browserSoon($page, "location.pathname === '/sa/en/sign-in'"))->toBeTrue();

    $reads = "performance.getEntriesByType('resource').filter((entry) => new URL(entry.name).pathname === '/sa/en').length";
    $readsBefore = (int) $page->script($reads);
    $page->script('(() => { history.back(); })()');
    expect(browserSoon($page, "location.pathname === '/sa/en'"))->toBeTrue();
    usleep(500_000);
    expect((int) $page->script($reads))->toBe($readsBefore)
        ->and($page->script('history.state.page instanceof ArrayBuffer'))->toBeFalse();
    $page->assertNoJavaScriptErrors();
});
