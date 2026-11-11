<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Content\Application\Command\SaveBar\SaveBar;
use Modules\Content\Application\Command\SaveBar\SaveBarHandler;
use Modules\Content\Application\Command\SaveFooter\SaveFooter;
use Modules\Content\Application\Command\SaveFooter\SaveFooterHandler;
use Modules\Content\Application\Command\SaveTheme\SaveTheme;
use Modules\Content\Application\Command\SaveTheme\SaveThemeHandler;
use Modules\Content\Application\Command\SchedulePopup\SchedulePopup;
use Modules\Content\Application\Command\SchedulePopup\SchedulePopupHandler;
use Modules\Content\Application\Command\ScheduleTheme\ScheduleTheme;
use Modules\Content\Application\Command\ScheduleTheme\ScheduleThemeHandler;
use Modules\Content\Application\ContentCache;
use Modules\Pricing\Application\Command\AddSale\AddSale;
use Modules\Pricing\Application\Command\AddSale\AddSaleHandler;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Content\Support\ContentBrowser as Cb;
use Tests\Modules\Content\Support\ContentFixtures as Cx;
use Tests\Modules\Pricing\Support\PricingFixtures as Rx;

use function Pest\Laravel\seed;

/*
| The shop's frame in a real browser (content.md §1.5, §1.8 part 11, §1.10; plan PR 9): the announcement bar, the sticky
| header and its phone form (52 px, the search row, the tab bar), the drawer of the owner's option C3 with Explore, the
| store's footer, the pop-up once per visitor (a Dialog wider, a Sheet on a phone), and a theme's edges on the right side
| in each direction - at 375 x 812 and at a computer's width, in both languages and both modes, nothing sideways.
|
| Deliberately no RefreshDatabase: each run writes in `ae`, whose bar, footer, pop-ups and themes it sets first.
*/

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    $ae = Fx::storeId('ae');
    DB::table('content.popups')->where('store_id', $ae)->delete();
    DB::table('content.theme_stores')->where('store_id', $ae)->delete();
    Fx::asSystem(function () use ($ae): void {
        $bar = DB::table('content.bars')->where('store_id', $ae)->value('version');
        app(SaveBarHandler::class)->handle(new SaveBar($ae, (int) ($bar ?? 0), 'توصيل مجاني لكل الطلبات فوق ٣٠٠ درهم داخل الإمارات', 'Free delivery on every order over 300 AED anywhere in the Emirates', null, true));
        $footer = DB::table('content.footers')->where('store_id', $ae)->value('version');
        app(SaveFooterHandler::class)->handle(new SaveFooter($ae, (int) ($footer ?? 0), 'إكسسوارات المطابخ والخزائن', 'Kitchen and cabinet hardware', '+971 4 000 0000', 'support@example.test', 'دبي', 'Dubai', 'الحقوق محفوظة', 'All rights reserved', [
            ['titleAr' => 'المساعدة', 'titleEn' => 'Help', 'links' => [['textAr' => 'الإرجاع', 'textEn' => 'Returns', 'link' => '/pages/returns'], ['textAr' => 'تواصل معنا', 'textEn' => 'Contact Us', 'link' => '/pages/contact']]],
        ]));
    });
    app(ContentCache::class)->invalidate();
});

/** A width and a language, the page settled, and whether anything scrolls sideways. */
function shopFrameBrowserFits(mixed $page): void
{
    expect(Cb::fitsPhone($page))->toBeTrue(Cb::overflow($page));
}

it('draws the frame at a computer\'s width and on a phone, both languages and both modes, nothing sideways', function () {
    $page = visit('/ae/en');
    expect(browserSoon($page, "document.querySelector('[data-test=\"announcement-bar\"]')?.textContent.includes('Free delivery') === true"))->toBeTrue()
        ->and(browserSoon($page, "document.querySelector('[data-test=\"store-footer\"]')?.textContent.includes('Contact Us') === true"))->toBeTrue()
        // The footer's links are the shop's own, under the page's store and language.
        ->and($page->script("document.querySelector('[data-test=\"store-footer\"] a[href=\"/ae/en/pages/returns\"]') !== null"))->toBeTrue()
        // Wider than a phone: no tab bar, the header's cart and country.
        ->and($page->script("document.querySelector('[data-test=\"tab-bar\"]') === null || getComputedStyle(document.querySelector('[data-test=\"tab-bar\"]')).display === 'none'"))->toBeTrue()
        ->and($page->script("getComputedStyle(document.querySelector('[data-test=\"country-switch\"]')).display !== 'none'"))->toBeTrue();
    shopFrameBrowserFits($page);

    foreach (['/ae/en', '/ae/ar'] as $address) {
        foreach (['light', 'dark'] as $mode) {
            $phone = $mode === 'dark' ? visit($address)->inDarkMode() : visit($address);
            $phone->resize(375, 812);
            $phone->navigate($address, BROWSER_PAGE_LOAD);

            // 52 px of menu, logo, theme and favourites, the search row under it, the tab bar at the bottom.
            expect(browserSoon($phone, "document.querySelector('[data-test=\"tab-bar\"]') !== null && document.querySelectorAll('[data-test=\"tab-bar\"] a, [data-test=\"tab-bar\"] button').length >= 4"))->toBeTrue("{$address} {$mode}: no tab bar")
                ->and($phone->script("Math.round(document.querySelector('header a[href=\"{$address}\"]').getBoundingClientRect().height)"))->toBe(52)
                ->and($phone->script("getComputedStyle(document.querySelector('[data-test=\"search-row\"]')).display !== 'none' && document.querySelector('[data-test=\"search-row\"]').getBoundingClientRect().top >= 52"))->toBeTrue()
                ->and($phone->script("(() => { const bar = document.querySelector('[data-test=\"tab-bar\"]').getBoundingClientRect(); return Math.round(bar.bottom) === window.innerHeight; })()"))->toBeTrue()
                ->and($phone->script("getComputedStyle(document.querySelector('[data-test=\"header-cart\"]')?.parentElement ?? document.body).display === 'none' || document.querySelector('[data-test=\"header-cart\"]') === null"))->toBeTrue()
                // One line, cut on a phone.
                ->and($phone->script("document.querySelector('[data-test=\"announcement-bar\"]').getBoundingClientRect().height <= 40"))->toBeTrue()
                ->and(Cb::latinDigits($phone))->toBeTrue();
            shopFrameBrowserFits($phone);
            $phone->assertNoJavaScriptErrors();
        }
    }
});

it('opens the drawer of option C3: categories, then account and settings with the country, language and theme at the bottom; Explore opens search', function () {
    $page = visit('/ae/ar');
    $page->resize(375, 812);
    $page->navigate('/ae/ar', BROWSER_PAGE_LOAD);

    $page->click('[data-test="category-sheet-open"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"drawer-switch\"]') !== null"))->toBeTrue()
        // From the page's start side: the right in Arabic (as ShopPagesTest measures it: innerWidth counts the scroll lock's gap).
        ->and(browserUntil($page, "(() => { const r = document.querySelector('[data-test=\"category-sheet\"]').getBoundingClientRect(); return Math.round(r.width) <= 320 && r.left > 20 && r.right > window.innerWidth * 0.9; })()"))->toBeTrue('the drawer is not on the right in Arabic')
        ->and($page->script("document.querySelector('[data-test=\"drawer-categories\"]').getAttribute('data-state')"))->toBe('active');

    $page->click('[data-test="drawer-account"]');
    expect(browserSoon($page, "document.querySelector('[data-test=\"drawer-country\"]') !== null && document.querySelector('[data-test=\"drawer-sign-in\"]') !== null"))->toBeTrue()
        ->and($page->script("document.querySelector('[data-test=\"drawer-language-en\"]')?.getAttribute('href')"))->toBe('/ae/en')
        ->and($page->script("document.querySelector('[data-test=\"drawer-theme\"]')?.textContent"))->toContain('داكن')
        ->and(browserUntil($page, "(() => { const r = document.querySelector('[data-test=\"category-sheet\"]').getBoundingClientRect(); return r.left >= 0 && r.right <= window.innerWidth; })()"))->toBeTrue();

    $page->click('[data-test="drawer-theme"]');
    expect(browserUntil($page, "document.documentElement.dataset.mode === 'dark'"))->toBeTrue();

    $page->navigate('/ae/en', BROWSER_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"tab-explore\"]') !== null"))->toBeTrue();
    $page->click('[data-test="tab-explore"]');
    expect(browserUntil($page, "document.activeElement?.getAttribute('data-test') === 'search-input'"))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

it('shows the pop-up once per visitor after its delay: a Dialog at a computer\'s width, a short Sheet with no image on a phone', function () {
    $run = Cb::run();
    Fx::asSystem(function () use ($run): void {
        $popup = Cx::popup(['titleEn' => "Spring offers {$run}", 'titleAr' => "عروض الربيع {$run}", 'delaySeconds' => 3, 'mediaId' => Cx::image()], 'ae');
        app(SchedulePopupHandler::class)->handle(new SchedulePopup($popup, 1));
    });
    app(ContentCache::class)->invalidate();

    $page = visit('/ae/en');
    // Not at once: after its delay.
    expect($page->script("document.querySelector('[data-test=\"popup\"]') === null"))->toBeTrue()
        ->and(browserSoon($page, "document.querySelector('[data-test=\"popup\"]')?.getAttribute('data-kind') === 'dialog'", 10))->toBeTrue()
        ->and($page->script("document.querySelector('[data-test=\"popup\"]').textContent.includes('Spring offers {$run}')"))->toBeTrue()
        ->and($page->script("Math.round(document.querySelector('[data-test=\"popup\"]').getBoundingClientRect().width) <= 420"))->toBeTrue();
    // Its button follows its link under the page's store and language, and closes it (§1.10).
    expect($page->script("document.querySelector('[data-test=\"popup-button\"]')?.getAttribute('href')"))->toBe('/ae/en/categories/kitchens');
    $page->click('[data-test="popup-button"]');
    expect(browserSoon($page, "location.pathname === '/ae/en/categories/kitchens' && document.querySelector('[data-test=\"popup\"]') === null"))->toBeTrue();

    // The same visitor again: never twice.
    $page->navigate('/ae/en/search?q=a', BROWSER_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"popup\"]') !== null", 6))->toBeFalse();

    // A new visitor on a phone: a Sheet of at most 40% of the height, its image left out.
    $phone = visit('/ae/ar');
    $phone->resize(375, 812);
    $phone->script("(() => { localStorage.removeItem('tw:popups'); })()");
    $phone->navigate('/ae/ar', BROWSER_PAGE_LOAD);
    expect(browserSoon($phone, "document.querySelector('[data-test=\"popup\"]')?.getAttribute('data-kind') === 'sheet'", 10))->toBeTrue()
        ->and($phone->script("document.querySelector('[data-test=\"popup\"]').getBoundingClientRect().height <= 812 * 0.4 + 1"))->toBeTrue()
        ->and($phone->script("document.querySelector('[data-test=\"popup\"] img') === null"))->toBeTrue();
    shopFrameBrowserFits($phone);
    $phone->assertNoJavaScriptErrors();
});

it('draws a theme\'s edges outside the content column from 1025 px, the start side\'s on the right in Arabic and on the left in English', function () {
    $run = Cb::run();
    Fx::asSystem(function () use ($run): void {
        $theme = app(SaveThemeHandler::class)->handle(new SaveTheme(null, 0, "حافة {$run}", "Edge {$run}", Cx::typed(-60, 'ae'), Cx::typed(60 * 24, 'ae'), [Fx::storeId('ae')], [
            ['part' => 'BACKDROP', 'images' => ['START:any' => Cx::image(mime: 'image/png'), 'END:any' => Cx::image(mime: 'image/png')]],
        ]));
        app(ScheduleThemeHandler::class)->handle(new ScheduleTheme($theme, (int) DB::table('content.themes')->where('id', $theme)->value('version')));
    });
    app(ContentCache::class)->invalidate();
    // The page's ground is the layout's outer box, which wears `--tw-backdrop` as its whole background.
    $ground = '(() => getComputedStyle(document.querySelector(\'[class*="--tw-backdrop"]\')).backgroundPositionX)()';

    $page = visit('/ae/en');
    $page->resize(1440, 900);
    $page->navigate('/ae/en', BROWSER_PAGE_LOAD);
    expect(browserSoon($page, "getComputedStyle(document.documentElement).getPropertyValue('--tw-backdrop').includes('image-set')"))->toBeTrue();
    $en = (string) $page->script($ground);

    $page->navigate('/ae/ar', BROWSER_PAGE_LOAD);
    expect(browserSoon($page, "document.documentElement.dir === 'rtl' && getComputedStyle(document.documentElement).getPropertyValue('--tw-backdrop').includes('image-set')"))->toBeTrue();
    $ar = (string) $page->script($ground);

    // The first layer is the start side's: left of the column in English, right of it in Arabic.
    expect($en)->toStartWith('calc(50% - ')
        ->and($ar)->toStartWith('calc(50% + ');

    // 1024 and under: the plain ground.
    $page->resize(1024, 800);
    expect(browserSoon($page, "!getComputedStyle(document.documentElement).getPropertyValue('--tw-backdrop').includes('image-set')"))->toBeTrue();
    shopFrameBrowserFits($page);
    $page->assertNoJavaScriptErrors();

    DB::table('content.theme_stores')->where('store_id', Fx::storeId('ae'))->delete();
    app(ContentCache::class)->invalidate();
    CarbonImmutable::setTestNow();
});

it('marks only a reduced price with the theme\'s flag and badge on its card, and its ribbon and flag on its gallery', function () {
    // catalog.md amendment 41(e), content.md Q9 (a): the price before above the price now, and nothing else, wears them.
    seed(CatalogSeeder::class);
    Px::numberFrom((int) substr((string) hrtime(true), -8));
    $sa = Fx::storeId('sa');
    DB::table('content.theme_stores')->where('store_id', $sa)->delete();
    $category = Px::category('Kitchens');
    $sale = Px::ready(['60 cm'], $category);
    $plain = Px::ready(['60 cm'], $category);

    foreach ([$sale, $plain] as $p) {
        Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore($sa, $p['product'], true)));
        Px::onSale('sa', $p['product']);
        Rx::set('sa', [$p['variants'][0] => '30.00']);
    }

    // On a whole second, a sale starting now starts at once (Pricing's SaleInput).
    CarbonImmutable::setTestNow(CarbonImmutable::now()->startOfSecond());
    Fx::asSystem(fn () => app(AddSaleHandler::class)->handle(new AddSale($sa, null, [$sale['variants'][0]], null, '24.00', null, null)));
    Fx::asSystem(function () use ($sa): void {
        $theme = app(SaveThemeHandler::class)->handle(new SaveTheme(null, 0, 'علم '.Cb::run(), 'Flag '.Cb::run(), Cx::typed(-60), Cx::typed(60 * 24), [$sa], [
            ['part' => 'FLAG', 'images' => ['FLAG:any' => Cx::image(mime: 'image/png'), 'RIBBON:any' => Cx::image(mime: 'image/png')]],
            ['part' => 'BADGE', 'textAr' => 'عرض', 'textEn' => 'Offer'],
        ]));
        app(ScheduleThemeHandler::class)->handle(new ScheduleTheme($theme, (int) DB::table('content.themes')->where('id', $theme)->value('version')));
    });
    CarbonImmutable::setTestNow();
    app(ContentCache::class)->invalidate();
    $slug = static fn (string $kind, string $id): string => (string) DB::table("catalog.{$kind}_slugs")->where("{$kind}_id", $id)->where('locale', 'en')->where('is_current', true)->value('slug');

    try {
        $page = visit('/sa/en/categories/'.$slug('category', $category));
        expect(browserSoon($page, "document.querySelectorAll('[data-test=\"card\"]').length === 2"))->toBeTrue()
            // The card with a price before wears the flag and the badge; the other neither.
            ->and($page->script("[...document.querySelectorAll('[data-test=\"card\"]')].map((card) => [card.querySelector('[data-test=\"price-before\"]') !== null, card.querySelector('[data-test=\"theme-flag\"]') !== null, card.querySelector('[data-test=\"theme-badge\"]')?.textContent ?? null]).sort((a, b) => Number(b[0]) - Number(a[0]))"))->toBe([[true, true, 'Offer'], [false, false, null]]);

        $page->navigate('/sa/en/products/'.$slug('product', $sale['product']), BROWSER_PAGE_LOAD);
        expect(browserSoon($page, "document.querySelector('[data-test=\"gallery\"] [data-test=\"theme-ribbon\"]') !== null"))->toBeTrue()
            ->and($page->script("document.querySelector('[data-test=\"gallery\"] [data-test=\"theme-flag\"]') !== null && document.querySelector('[data-test=\"theme-badge\"]')?.textContent === 'Offer'"))->toBeTrue();

        $page->navigate('/sa/en/products/'.$slug('product', $plain['product']), BROWSER_PAGE_LOAD);
        expect(browserSoon($page, "document.querySelector('[data-test=\"gallery\"]') !== null"))->toBeTrue()
            // Its own gallery and price wear nothing; a related card of the reduced product may, as any card.
            ->and($page->script("document.querySelector('[data-test=\"gallery\"] [data-test=\"theme-ribbon\"], [data-test=\"gallery\"] [data-test=\"theme-flag\"]') === null && [...document.querySelectorAll('[data-test=\"theme-badge\"]')].every((badge) => badge.closest('[data-test=\"card\"]') !== null)"))->toBeTrue();
        $page->assertNoJavaScriptErrors();
    } finally {
        // The theme leaves Saudi Arabia's shop, which the other browser tests read.
        DB::table('content.theme_stores')->where('store_id', $sa)->delete();
        app(ContentCache::class)->invalidate();
    }
});
