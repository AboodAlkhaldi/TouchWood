<?php

declare(strict_types=1);

use App\Http\StorefrontArea;
use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia;
use Modules\Content\Application\Command\SaveBar\SaveBar;
use Modules\Content\Application\Command\SaveBar\SaveBarHandler;
use Modules\Content\Application\Command\SaveFooter\SaveFooter;
use Modules\Content\Application\Command\SaveFooter\SaveFooterHandler;
use Modules\Content\Application\Command\SaveSmartBar\SaveSmartBar;
use Modules\Content\Application\Command\SaveSmartBar\SaveSmartBarHandler;
use Modules\Content\Application\Command\SaveTheme\SaveTheme;
use Modules\Content\Application\Command\SaveTheme\SaveThemeHandler;
use Modules\Content\Application\Command\ScheduleBanner\ScheduleBanner;
use Modules\Content\Application\Command\ScheduleBanner\ScheduleBannerHandler;
use Modules\Content\Application\Command\SchedulePopup\SchedulePopup;
use Modules\Content\Application\Command\SchedulePopup\SchedulePopupHandler;
use Modules\Content\Application\Command\ScheduleTheme\ScheduleTheme;
use Modules\Content\Application\Command\ScheduleTheme\ScheduleThemeHandler;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Query\Shop\ContentFrame;
use Modules\Content\Application\Query\Shop\ThemeStyle;
use Modules\Content\Presentation\Http\ThemePreviewCookie;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Content\Support\ContentFixtures as Cx;

use function Pest\Laravel\get;
use function Pest\Laravel\seed;

/*
| The shop's frame over HTTP (content.md §1.5, §1.8, §1.10; plan PR 9): Content's part of every shop page
| (`frame.content`), the live theme on the shell (`theme.campaign`, `theme.style`, from `content.storefront-theme`),
| what goes above a product list (`slots.content`), and Preview Theme on the shop. All from **one cached read** a
| page, filtered by the clock - so each starts and ends on the second through a warm cache.
|
| Every helper here is named after this file's subject: a Pest file's functions are global.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    config(['session.lottery' => [0, 100]]);
    Sleep::fake();
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-11 09:00:00+00'));
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    Carbon\Carbon::setTestNow();
});

/**
 * A theme of the store, scheduled from a minute ahead for a week, its parts as given, as a Super Admin.
 *
 * @param  list<array<string, mixed>>  $parts
 */
function shopFrameTheme(array $parts, string $nameEn = 'Ramadan', string $code = 'sa', bool $schedule = true): string
{
    // As the system, so the requests after it act as their own session says (the real ActorContext back).
    return Fx::asSystem(function () use ($parts, $nameEn, $code, $schedule): string {
        $theme = app(SaveThemeHandler::class)->handle(new SaveTheme(null, 0, 'رمضان', $nameEn, Cx::typed(1, $code), Cx::typed(60 * 24 * 7, $code), [Fx::storeId($code)], $parts));

        if ($schedule) {
            app(ScheduleThemeHandler::class)->handle(new ScheduleTheme($theme, (int) DB::table('content.themes')->where('id', $theme)->value('version')));
        }

        return $theme;
    });
}

/** A live pop-up of the store, scheduled as the system. */
function shopFramePopup(): string
{
    return Fx::asSystem(function (): string {
        $popup = Cx::popup();
        app(SchedulePopupHandler::class)->handle(new SchedulePopup($popup, 1));

        return $popup;
    });
}

/** A live listing banner for every list, scheduled as the system. */
function shopFrameListingBanner(): string
{
    return Fx::asSystem(function (): string {
        $banner = Cx::banner(['placement' => 'LISTING', 'images' => ['DESKTOP:any' => Cx::image(), 'PHONE:any' => Cx::image()]]);
        app(ScheduleBannerHandler::class)->handle(new ScheduleBanner($banner, 1));

        return $banner;
    });
}

/** Every part a frame draws, artwork ready. */
function shopFrameParts(string $badge = 'عرض'): array
{
    return [
        ['part' => 'ANNOUNCEMENT', 'textAr' => 'رمضان كريم', 'textEn' => 'Ramadan Kareem', 'link' => '/categories/kitchens', 'images' => ['DESKTOP:any' => Cx::image(), 'PHONE:any' => Cx::image()]],
        ['part' => 'PROMISES', 'images' => ['BAND:any' => Cx::image()]],
        ['part' => 'FLAG', 'images' => ['FLAG:any' => Cx::image(mime: 'image/png'), 'RIBBON:any' => Cx::image(mime: 'image/png')]],
        ['part' => 'BADGE', 'textAr' => $badge, 'textEn' => 'Offer'],
        ['part' => 'LISTING', 'images' => ['DESKTOP:ar' => Cx::image(), 'DESKTOP:en' => Cx::image(), 'PHONE:ar' => Cx::image(), 'PHONE:en' => Cx::image()]],
        ['part' => 'CART', 'textAr' => 'شحن مجاني في رمضان', 'textEn' => 'Free delivery in Ramadan'],
        ['part' => 'FOOTER', 'images' => ['BAND:any' => Cx::image()]],
        ['part' => 'BACKDROP', 'images' => ['START:any' => Cx::image(mime: 'image/png'), 'END:any' => Cx::image(mime: 'image/png')]],
    ];
}

/** A shop page's props, as Inertia hands them; the page is a fresh request. */
function shopFramePage(string $url): array
{
    app()->forgetScopedInstances();
    $props = [];
    get($url)->assertOk()->assertInertia(function (AssertableInertia $page) use (&$props): void {
        $props = $page->toArray()['props'];
    });

    return $props;
}

/**
 * The queries a request makes, by SQL.
 *
 * @return list<string>
 */
function shopFrameQueries(string $url): array
{
    app()->forgetScopedInstances();
    /** @var ArrayObject<int, string> $queries */
    $queries = new ArrayObject;
    DB::listen(function (QueryExecuted $query) use ($queries): void {
        $queries[] = $query->sql;
    });
    get($url)->assertOk();

    return array_values($queries->getArrayCopy());
}

describe('Content\'s part of the frame (§1.5)', function () {
    it('carries the bar, the footer and the frame\'s words on a shop page, each in its language', function () {
        Fx::asSystem(function (): void {
            app(SaveBarHandler::class)->handle(new SaveBar(Fx::storeId('sa'), 0, 'شحن مجاني', 'Free delivery', '/categories/kitchens', true));
            app(SaveFooterHandler::class)->handle(new SaveFooter(Fx::storeId('sa'), 0, 'متجر', 'A store', '+966 54 592 0426', 'support@example.test', 'جدة', 'Jeddah', 'الحقوق محفوظة', 'All rights reserved', [
                ['titleAr' => 'المساعدة', 'titleEn' => 'Help', 'links' => [['textAr' => 'الإرجاع', 'textEn' => 'Returns', 'link' => '/pages/returns']]],
            ]));
        });

        $en = shopFramePage('/sa/en')['frame']['content'];
        $ar = shopFramePage('/sa/ar')['frame']['content'];

        expect($en['bar'])->toBe(['text' => 'Free delivery', 'link' => '/categories/kitchens'])
            ->and($ar['bar']['text'])->toBe('شحن مجاني')
            ->and($en['footer']['about'])->toBe('A store')
            ->and($en['footer']['columns'][0])->toBe(['title' => 'Help', 'links' => [['text' => 'Returns', 'link' => '/pages/returns']]])
            ->and($ar['footer']['address'])->toBe('جدة')
            ->and($en['theme'])->toBeNull()
            ->and($en['preview'])->toBeNull()
            ->and($en['words']['explore'])->toBe('Explore')
            ->and($ar['words']['explore'])->toBe('استكشف');
    });

    it('draws a pop-up only on the pages a shopper browses: never on the cart or sign-in', function () {
        $popup = shopFramePopup();

        expect(shopFramePage('/sa/en')['frame']['content']['popup']['id'] ?? null)->toBe($popup)
            ->and(shopFramePage('/sa/en/search?q=Drawer')['frame']['content']['popup']['id'] ?? null)->toBe($popup)
            ->and(shopFramePage('/sa/en/cart')['frame']['content']['popup'])->toBeNull()
            ->and(shopFramePage('/sa/en/sign-in')['frame']['content']['popup'])->toBeNull();
    });

    it('starts and ends the theme\'s parts on the second through a warm cache, the frame costing its two cached reads', function () {
        $theme = shopFrameTheme(shopFrameParts());

        // Read before the start, so the cache is warm when it comes.
        $before = shopFramePage('/sa/en');
        expect($before['frame']['content']['theme'])->toBeNull()
            ->and($before['theme']['campaign'])->toBe('base')
            ->and($before['theme']['style'] ?? null)->toBeNull();

        Cx::clockOn(2);
        $warm = shopFrameQueries('/sa/en');
        $live = shopFramePage('/sa/en');

        // Warm, no table of Content's is read: the cache's version and its copy, once for the part, the shell and a list.
        expect(array_values(array_filter($warm, static fn (string $sql): bool => str_contains($sql, 'content.'))))->toBe([])
            ->and($live['theme']['campaign'])->toBe('ramadan')
            ->and($live['frame']['content']['theme']['badge'])->toBe('Offer')
            ->and($live['frame']['content']['theme']['cart'])->toBe(['text' => 'Free delivery in Ramadan', 'link' => null])
            ->and($live['frame']['content']['theme']['flag'])->toEndWith('.webp')
            ->and($live['frame']['content']['theme']['ribbon'])->toEndWith('.webp')
            ->and($live['frame']['content']['theme']['footerArt'])->toBeTrue()
            // The bar dresses in the theme's words with nothing of the store's on: its strip behind them.
            ->and($live['frame']['content']['bar'])->toBe(['text' => 'Ramadan Kareem', 'link' => '/categories/kitchens', 'art' => true])
            ->and($live['theme']['style'])->toContain('--tw-theme-bar: image-set(')
            ->and($live['theme']['style'])->toContain('--tw-theme-promises')
            ->and($live['theme']['style'])->toContain('--tw-theme-footer')
            ->and($live['theme']['style'])->toContain('--tw-backdrop');

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addDays(8));
        $after = shopFramePage('/sa/en');

        expect($after['theme']['campaign'])->toBe('base')
            ->and($after['frame']['content']['theme'])->toBeNull()
            ->and(DB::table('content.themes')->where('id', $theme)->exists())->toBeTrue();
    });

    it('writes theme.style from media sizes only: a typed </style> or a script never reaches it, and part 11 by direction', function () {
        $hostile = '</style><script>alert(1)</script>';
        shopFrameTheme([...shopFrameParts(), ['part' => 'POINTS', 'textAr' => $hostile, 'textEn' => $hostile, 'images' => ['BAND:any' => Cx::image()]]]);
        Cx::clockOn(2);

        $en = shopFramePage('/sa/en')['theme']['style'];
        $ar = shopFramePage('/sa/ar')['theme']['style'];

        expect($en)->not->toContain('</style')
            ->and($en)->not->toContain('<script')
            ->and($en)->not->toContain('alert')
            // Transparent parts WebP only; the bands AVIF and WebP; never the original file.
            ->and(preg_match('~--tw-backdrop: image-set\(url\("[^"]+\.webp"\) type\("image/webp"\)\)~', $en))->toBe(1)
            ->and($en)->not->toContain('image/jpeg')
            ->and($en)->toContain('type("image/avif")')
            // The start side's image ends at the column's start edge: on the left in English, on the right in Arabic.
            ->and(strpos($en, 'calc(50% - 36rem - 240px)'))->toBeLessThan((int) strpos($en, 'calc(50% + 36rem + 240px)'))
            ->and(strpos($ar, 'calc(50% + 36rem + 240px)'))->toBeLessThan((int) strpos($ar, 'calc(50% - 36rem - 240px)'))
            // Hidden at 1024 and under; softened in dark by half the overlay token.
            ->and($en)->toContain('@media (min-width: 1025px)')
            ->and($en)->toContain("html[data-mode='dark']");
    });

    it('refuses any address that could leave its url("…")', function (string $url, bool $safe) {
        expect(ThemeStyle::safe($url))->toBe($safe);
    })->with([
        'a CDN address' => ['https://cdn.example.test/media/01/detail.webp', true],
        'a root-relative one' => ['/storage/media/01/detail.avif', true],
        'a quote' => ['https://cdn.example.test/a".webp', false],
        'a closing bracket' => ['https://cdn.example.test/a).webp', false],
        'a style end' => ['https://cdn.example.test/</style>', false],
        'a backslash' => ['https://cdn.example.test/a\\b.webp', false],
        'a space' => ['https://cdn.example.test/a b.webp', false],
        'another host by //' => ['//evil.example/a.webp', false],
        'javascript' => ['javascript:alert(1)', false],
    ]);

    it('leaves the panel and an error page drawn outside the shop in base while a theme is live', function () {
        shopFrameTheme(shopFrameParts());
        Cx::clockOn(2);

        get('/admin/sign-in')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('theme.campaign', 'base')->missing('theme.style'));
        expect(StorefrontArea::MIDDLEWARE)->toContain(StorefrontArea::THEME)
            ->and(array_search(StorefrontArea::THEME, StorefrontArea::MIDDLEWARE, true))->toBe(array_search(StorefrontArea::STORE, StorefrontArea::MIDDLEWARE, true) + 1)
            ->and(StorefrontArea::MIDDLEWARE_FINISHING)->toContain(StorefrontArea::THEME)
            ->and(StorefrontArea::MIDDLEWARE_WITHOUT_STORE)->not->toContain(StorefrontArea::THEME);
    });
});

describe('above a product list (§1.9, §1.6 LISTING, §1.8 part 8; catalog.md amendment 41(a))', function () {
    it('shows the nearest category\'s smart bar first, then a campaign\'s, and the theme\'s listing banner ahead of an ordinary one', function () {
        $top = Px::category('Kitchens');
        $under = Px::category('Cabinets', $top);
        Cx::editor([ContentPermissions::BAR_MANAGE, ContentPermissions::BANNER_MANAGE]);
        $bar = static fn (string $category, string $en) => app(SaveSmartBarHandler::class)->handle(new SaveSmartBar(null, Fx::storeId('sa'), 'CATEGORY', $category, null, 'خصم', $en, null));
        $bar($top, 'Kitchens on sale');
        $banner = Cx::banner(['placement' => 'LISTING', 'images' => ['DESKTOP:any' => Cx::image(), 'PHONE:any' => Cx::image()]]);
        app(ScheduleBannerHandler::class)->handle(new ScheduleBanner($banner, 1));
        $store = Cx::store();
        $kept = app(ContentFrame::class)->kept($store, 'en');
        $now = CarbonImmutable::now();

        // The top category's bar reaches the one under it; a brand's or a search's list has no category.
        expect(ContentFrame::listing($kept, 'en', [$top, $under], $now)['smartBar'])->toBe(['text' => 'Kitchens on sale', 'link' => null])
            ->and(ContentFrame::listing($kept, 'en', [], $now)['smartBar'])->toBeNull()
            ->and(ContentFrame::listing($kept, 'en', [], $now)['banner']['link'])->toBe('/categories/summer');

        $bar($under, 'Cabinets on sale');
        $kept = app(ContentFrame::class)->kept($store, 'en');

        expect(ContentFrame::listing($kept, 'en', [$top, $under], $now)['smartBar']['text'])->toBe('Cabinets on sale')
            ->and(ContentFrame::listing($kept, 'en', [$top], $now)['smartBar']['text'])->toBe('Kitchens on sale');

        shopFrameTheme(shopFrameParts());
        Cx::clockOn(2);
        $themed = ContentFrame::listing(app(ContentFrame::class)->kept($store, 'en'), 'en', [$top], CarbonImmutable::now())['banner'];

        expect($themed['link'])->toBeNull()
            ->and($themed['desktop'])->not->toBe([]);
    });

    it('carries the slot on a search page, read once with the frame, and never on a Show More', function () {
        shopFrameListingBanner();

        $props = shopFramePage('/sa/en/search?q=Drawer');

        expect($props['slots']['content']['banner']['link'])->toBe('/categories/summer')
            ->and($props['slots']['content']['smartBar'])->toBeNull();

        app()->forgetScopedInstances();
        $version = (string) (get('/sa/en/search?q=Drawer')->viewData('page')['version'] ?? '');
        app()->forgetScopedInstances();
        $partial = get('/sa/en/search?q=Drawer', ['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'Catalog/Storefront/Search', 'X-Inertia-Partial-Data' => 'products,next,cardFacts', 'X-Inertia-Version' => $version]);
        $partial->assertOk();

        expect($partial->json('props'))->not->toHaveKey('slots');
    });
});

describe('Preview Theme on the shop (W5b)', function () {
    /** A browser signed in to the panel. */
    function shopFrameStaffBrowser(string $staff): AdminBrowser
    {
        $browser = new AdminBrowser('10.9.8.'.random_int(20, 250));
        $browser->post('/admin/sign-in', ['email' => (string) DB::table('access.staff_users')->where('id', $staff)->value('email'), 'password' => Fx::STAFF_PASSWORD])->assertRedirect('/admin/sign-in/code');
        $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode()])->assertRedirect('/admin');

        return $browser;
    }

    beforeEach(function () {
        config(['session.driver' => 'database']);
        FakeBreachList::install();
        RecordingSecurityMessages::install();
    });

    it('shows its presser\'s staff view the draft theme past the cache, kept nowhere and out of search engines; nobody else', function () {
        $super = Fx::staff(superAdmin: true);
        $theme = shopFrameTheme(shopFrameParts('معاينة'), 'Preview Me', schedule: false);
        // A live pop-up, which a staff view never draws (§1.10).
        shopFramePopup();
        DB::table('access.staff_users')->where('id', $super)->update(['locale' => 'en']);

        $browser = shopFrameStaffBrowser($super);
        $browser->post("/admin/content/themes/{$theme}/preview")->assertRedirect('/sa/ar');
        $browser->post('/admin/staff-view', ['store' => 'sa']);
        $page = $browser->get('/sa/en');

        $page->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex')
            ->assertInertia(fn (AssertableInertia $view) => $view->where('theme.campaign', 'preview-me')->where('frame.content.preview', 'Preview Me')->where('frame.content.theme.badge', 'Offer')->where('frame.content.popup', null));
        expect((string) $page->headers->get('Cache-Control'))->toContain('no-store');

        // The same cookie in a browser with no staff view: nothing.
        $cookie = (string) $browser->cookie(ThemePreviewCookie::NAME);
        expect($cookie)->not->toBe('');
        app()->forgetScopedInstances();
        $this->withCookie(ThemePreviewCookie::NAME, $cookie)->get('/sa/en')->assertOk()->assertInertia(fn (AssertableInertia $view) => $view->where('theme.campaign', 'base')->where('frame.content.preview', null));
    });

    it('shows nothing to the staff view of someone who is not a Super Admin, though the cookie is real', function () {
        $super = Fx::staff(superAdmin: true);
        $theme = shopFrameTheme(shopFrameParts(), 'Preview Me', schedule: false);
        $cookie = app(ThemePreviewCookie::class)->issue($theme, $super)->getValue();
        $admin = Fx::staffWith([ContentPermissions::BANNER_MANAGE], ['sa']);
        DB::table('access.staff_users')->where('id', $admin)->update(['locale' => 'en']);

        $browser = shopFrameStaffBrowser($admin);
        $browser->post('/admin/staff-view', ['store' => 'sa']);
        $browser->setCookie(ThemePreviewCookie::NAME, (string) $cookie);

        $browser->get('/sa/en')->assertOk()->assertHeaderMissing('X-Robots-Tag')->assertInertia(fn (AssertableInertia $view) => $view->where('theme.campaign', 'base')->where('frame.content.preview', null));
    });

    it('stops on the shop\'s own line: the cookie forgotten, back to the page', function () {
        $this->post('/sa/en/theme-preview/stop', [], ['Referer' => url('/sa/en/search?q=Drawer')])
            ->assertRedirect(url('/sa/en/search?q=Drawer'))
            ->assertCookieExpired(ThemePreviewCookie::NAME);
    });
});
