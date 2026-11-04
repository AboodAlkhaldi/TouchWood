<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Catalog\Support\CatalogProducts as Cx;
use Tests\Modules\Promotions\Support\PromotionsFixtures as Px;

use function Pest\Laravel\seed;

/*
| Going Back to a Show More list (lib/use-load-more.ts; owner, 2026-11-03), on the coupons list, whose rows link out:
| someone at row 80 opens it, goes Back, and is at row 80 with every row there, with no request; Show More goes on
| from there; a refresh starts the list again. And a customer's hearts, which arrive beside the rows, page by page.
|
| Deliberately no RefreshDatabase, as the other browser tests: the coupons have a prefix of their own, from the clock.
*/

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

function loadMoreBackPanel(): mixed
{
    $page = visit('/admin/sign-in')
        ->type('#email', (string) DB::table('access.staff_users')->where('id', Fx::staff(superAdmin: true))->value('email'))
        ->type('#password', Fx::STAFF_PASSWORD)
        ->click('button[type="submit"]')
        ->assertPathIs('/admin/sign-in/code')
        ->type('input[autocomplete="one-time-code"]', RecordingSecurityMessages::installed()->lastCode())
        ->click('button[type="submit"]');

    expect(signedInToPanel($page))->toBeTrue();

    return $page;
}

/** The 105 coupons of this run; the newest is -105. */
function loadMoreBackCoupons(): string
{
    $prefix = 'LB'.substr((string) hrtime(true), -7);

    foreach (range(1, 105) as $number) {
        Px::coupon(sprintf('%s-%03d', $prefix, $number));
    }

    return $prefix;
}

it('comes back to the same row of a long list, with every row there and no request, and goes on from it', function () {
    $prefix = loadMoreBackCoupons();
    $page = loadMoreBackPanel();
    $rows = "document.querySelectorAll('[data-test^=\"coupon-{$prefix}\"]')";
    // The row someone reached: the 80th from the top, which is coupon 26 of 105.
    $row80 = "document.querySelector('[data-test=\"coupon-{$prefix}-026\"]')";

    $page->navigate("/admin/coupons?store=sa&code={$prefix}", BROWSER_PAGE_LOAD);
    expect(browserSoon($page, "{$rows}.length === 50"))->toBeTrue();

    $page->click('[data-test="more"]');
    expect(browserSoon($page, "{$rows}.length === 100"))->toBeTrue();

    // Down to row 80, and into it.
    $page->script("(() => { {$row80}.scrollIntoView({ block: 'center' }); })()");
    expect(browserSoon($page, 'window.scrollY > 0'))->toBeTrue();
    $place = (int) $page->script('Math.round(window.scrollY)');
    // Inertia writes the place 100 ms after the scrolling stops; a person is slower than that.
    expect(browserSoon($page, "Math.abs((history.state?.documentScrollPosition?.top ?? 0) - {$place}) < 5"))->toBeTrue();
    $reads = "performance.getEntriesByType('resource').filter((entry) => entry.name.includes('/admin/coupons?')).length";
    $page->script("(() => { {$row80}.querySelector('a').click(); })()");
    expect(browserSoon($page, "location.pathname.startsWith('/admin/coupons/')"))->toBeTrue();
    $readsBefore = (int) $page->script($reads);

    // Back: every row, the newest on top, the same place, row 80 on the screen, and nothing read.
    $page->script('(() => { history.back(); })()');
    expect(browserSoon($page, "location.pathname === '/admin/coupons' && {$rows}.length > 0"))->toBeTrue();
    expect((int) $page->script("{$rows}.length"))->toBe(100)
        ->and($page->script("{$rows}[0].dataset.test"))->toBe("coupon-{$prefix}-105")
        ->and(browserSoon($page, "Math.abs(window.scrollY - {$place}) < 5", 5))->toBeTrue("scrolled to {$page->script('Math.round(window.scrollY)')}, was {$place}")
        ->and($page->script("(() => { const box = {$row80}.getBoundingClientRect(); return box.top >= 0 && box.bottom <= window.innerHeight; })()"))->toBeTrue();
    // Given the time a late request would take.
    usleep(500_000);
    expect((int) $page->script($reads))->toBe($readsBefore);
    $page->assertNoJavaScriptErrors();

    // Show More goes on from where it was: the last five, no row twice.
    $page->click('[data-test="more"]');
    expect(browserSoon($page, "{$rows}.length === 105"))->toBeTrue()
        ->and($page->script("new Set([...{$rows}].map((row) => row.dataset.test)).size"))->toBe(105);

    // And kept again: a second trip back brings all 105.
    $page->script("(() => { {$rows}[104].querySelector('a').click(); })()");
    expect(browserSoon($page, "location.pathname.startsWith('/admin/coupons/')"))->toBeTrue();
    $page->script('(() => { history.back(); })()');
    expect(browserSoon($page, "location.pathname === '/admin/coupons' && {$rows}.length === 105"))->toBeTrue();

    // A refresh starts the list again.
    $page->script('(() => { location.reload(); })()');
    expect(browserSoon($page, "{$rows}.length === 50"))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

it('opens the list afresh from a link, even after coming Back to it first', function () {
    $prefix = loadMoreBackCoupons();
    $page = loadMoreBackPanel();
    $rows = "document.querySelectorAll('[data-test^=\"coupon-{$prefix}\"]')";

    $page->navigate('/admin/coupons', BROWSER_PAGE_LOAD);
    expect(browserSoon($page, "{$rows}.length === 50"))->toBeTrue();
    $page->click('[data-test="more"]');
    expect(browserSoon($page, "{$rows}.length === 100"))->toBeTrue();

    // Into a row and Back: the kept rows come back.
    $page->script("(() => { {$rows}[79].querySelector('a').click(); })()");
    expect(browserSoon($page, "location.pathname.startsWith('/admin/coupons/')"))->toBeTrue();
    $page->script('(() => { history.back(); })()');
    expect(browserSoon($page, "location.pathname === '/admin/coupons' && {$rows}.length === 100"))->toBeTrue();

    // Into a row again, then the menu's link to this same address: going somewhere is not going Back.
    $page->script("(() => { {$rows}[0].querySelector('a').click(); })()");
    expect(browserSoon($page, "location.pathname.startsWith('/admin/coupons/')"))->toBeTrue();
    $page->script("(() => { document.querySelector('a[href\$=\"/admin/coupons\"]').click(); })()");
    expect(browserSoon($page, "location.pathname === '/admin/coupons' && {$rows}.length > 0"))->toBeTrue();
    usleep(500_000);
    expect((int) $page->script("{$rows}.length"))->toBe(50);
    $page->assertNoJavaScriptErrors();
});

it('starts the list again when a search changes it, keeps no old rows under the same first row, and fits a phone', function () {
    $prefix = loadMoreBackCoupons();
    $page = loadMoreBackPanel();
    $rows = "document.querySelectorAll('[data-test^=\"coupon-{$prefix}\"]')";

    $page->resize(375, 812);
    $page->navigate("/admin/coupons?store=sa&code={$prefix}", BROWSER_PAGE_LOAD);
    expect(browserSoon($page, "{$rows}.length === 50"))->toBeTrue();
    $page->script("(() => { document.querySelector('[data-test=\"more\"]').click(); })()");
    expect(browserSoon($page, "{$rows}.length === 100"))->toBeTrue()
        ->and(browserSoon($page, 'document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();

    // Into a row and Back, at phone width.
    $page->script("(() => { {$rows}[79].querySelector('a').click(); })()");
    expect(browserSoon($page, "location.pathname.startsWith('/admin/coupons/')"))->toBeTrue();
    $page->script('(() => { history.back(); })()');
    expect(browserSoon($page, "location.pathname === '/admin/coupons' && {$rows}.length === 100"))->toBeTrue();

    // A search whose only row is the one the kept rows are kept under (the second page's first): its row, not those.
    $page->clear('#code')->type('#code', "{$prefix}-055");
    expect(browserSoon($page, "{$rows}.length === 1"))->toBeTrue();
    $page->assertNoJavaScriptErrors();

    // Into that row and Back: still that search's one row.
    $page->script("(() => { {$rows}[0].querySelector('a').click(); })()");
    expect(browserSoon($page, "location.pathname.startsWith('/admin/coupons/')"))->toBeTrue();
    $page->script('(() => { history.back(); })()');
    expect(browserSoon($page, "location.pathname === '/admin/coupons' && {$rows}.length > 0"))->toBeTrue()
        ->and((int) $page->script("{$rows}.length"))->toBe(1);
});

it('brings every row back when coming back from a whole page load, as from another site', function () {
    $prefix = loadMoreBackCoupons();
    $page = loadMoreBackPanel();
    $rows = "document.querySelectorAll('[data-test^=\"coupon-{$prefix}\"]')";

    $page->navigate("/admin/coupons?store=sa&code={$prefix}", BROWSER_PAGE_LOAD);
    expect(browserSoon($page, "{$rows}.length === 50"))->toBeTrue();
    $page->click('[data-test="more"]');
    expect(browserSoon($page, "{$rows}.length === 100"))->toBeTrue();
    $page->script("(() => { document.querySelector('[data-test=\"coupon-{$prefix}-026\"]').scrollIntoView({ block: 'center' }); })()");
    expect(browserSoon($page, 'window.scrollY > 0'))->toBeTrue();
    usleep(300_000);
    $place = (int) $page->script('Math.round(window.scrollY)');

    // The browser leaves the app: a new document, as another site would be.
    $page->navigate('/admin/account', BROWSER_PAGE_LOAD);
    expect(browserSoon($page, "location.pathname === '/admin/account'"))->toBeTrue();
    $page->script('(() => { history.back(); })()');
    expect(browserSoon($page, "location.pathname === '/admin/coupons' && {$rows}.length > 0"))->toBeTrue();
    // Given the time the history entry takes to arrive after the first paint.
    usleep(800_000);
    expect((int) $page->script("{$rows}.length"))->toBe(100)
        ->and($page->script("{$rows}[0].dataset.test"))->toBe("coupon-{$prefix}-105")
        ->and(browserSoon($page, "Math.abs(window.scrollY - {$place}) < 5", 5))->toBeTrue("scrolled to {$page->script('Math.round(window.scrollY)')}, was {$place}");
    $page->assertNoJavaScriptErrors();
});

it('keeps a customer\'s hearts through Show More and Back, on every page of the list', function () {
    seed(CatalogSeeder::class);
    Cx::numberFrom((int) substr((string) hrtime(true), -8));
    $category = Cx::category('Hearts '.substr((string) hrtime(true), -9));

    foreach (range(1, 26) as $ignored) {
        $product = Cx::ready(['60 cm'], $category)['product'];
        Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId('sa'), $product, true)));
    }

    $email = strtolower((string) Str::ulid()).'@example.test';
    Fx::customer($email);
    $page = visit('/sa/en/sign-in')->type('#email', $email)->type('#password', Fx::CUSTOMER_PASSWORD)->click('button[type="submit"]');
    expect(browserSoon($page, "location.pathname === '/sa/en'"))->toBeTrue();

    $cards = "document.querySelectorAll('[data-test=\"card\"]')";
    $hearts = "document.querySelectorAll('[data-test=\"card-heart\"]')";
    $slug = (string) DB::table('catalog.category_slugs')->where('category_id', $category)->where('locale', 'en')->where('is_current', true)->value('slug');
    $page->navigate("/sa/en/categories/{$slug}", BROWSER_PAGE_LOAD);
    $opened = browserSoon($page, "{$cards}.length === 24");
    expect($opened)->toBeTrue("the category's list shows {$page->script("{$cards}.length")} cards at {$page->script('location.pathname')}");

    // A card on the first page saved, then the rest shown.
    $page->script("(() => { {$hearts}[0].click(); })()");
    expect(browserSoon($page, "{$hearts}[0].getAttribute('aria-pressed') === 'true'"))->toBeTrue();
    $page->click('[data-test="more"]');
    expect(browserSoon($page, "{$cards}.length === 26"))->toBeTrue();

    // A card on the last page saved: the list stays whole.
    $page->script("(() => { {$hearts}[25].click(); })()");
    expect(browserSoon($page, "{$hearts}[25]?.getAttribute('aria-pressed') === 'true'", 5))->toBeTrue("after a heart, {$page->script("{$cards}.length")} cards");
    usleep(500_000);
    expect((int) $page->script("{$cards}.length"))->toBe(26);

    // Into a card, and Back: every card, each heart as it was.
    $page->script("(() => { {$cards}[3].click(); })()");
    expect(browserSoon($page, "location.pathname.includes('/products/')"))->toBeTrue();
    $page->script('(() => { history.back(); })()');
    // Counted once the card's page is gone: its own related cards carry the same mark.
    expect(browserSoon($page, "location.pathname.includes('/categories/') && {$cards}.length === 26"))->toBeTrue("Back shows {$page->script("{$cards}.length")} cards")
        ->and($page->script("{$hearts}[0].getAttribute('aria-pressed')"))->toBe('true')
        ->and($page->script("{$hearts}[25].getAttribute('aria-pressed')"))->toBe('true')
        ->and($page->script("{$hearts}[1].getAttribute('aria-pressed')"))->toBe('false');
    $page->assertNoJavaScriptErrors();
});
