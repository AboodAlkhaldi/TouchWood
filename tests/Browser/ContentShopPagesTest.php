<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Content\Application\Command\AdvanceCampaigns\AdvanceCampaigns;
use Modules\Content\Application\Command\AdvanceCampaigns\AdvanceCampaignsHandler;
use Modules\Content\Application\Command\FillHomeSection\FillHomeSection;
use Modules\Content\Application\Command\FillHomeSection\FillHomeSectionHandler;
use Modules\Content\Application\Command\PublishPage\PublishPage;
use Modules\Content\Application\Command\PublishPage\PublishPageHandler;
use Modules\Content\Application\Command\PublishPost\PublishPost;
use Modules\Content\Application\Command\PublishPost\PublishPostHandler;
use Modules\Content\Application\Command\PublishProject\PublishProject;
use Modules\Content\Application\Command\PublishProject\PublishProjectHandler;
use Modules\Content\Application\Command\SaveCampaign\SaveCampaign;
use Modules\Content\Application\Command\SaveCampaign\SaveCampaignHandler;
use Modules\Content\Application\Command\SavePage\SavePage;
use Modules\Content\Application\Command\SavePage\SavePageHandler;
use Modules\Content\Application\Command\SavePost\SavePost;
use Modules\Content\Application\Command\SavePost\SavePostHandler;
use Modules\Content\Application\Command\SaveProject\SaveProject;
use Modules\Content\Application\Command\SaveProject\SaveProjectHandler;
use Modules\Content\Application\Command\ScheduleBanner\ScheduleBanner;
use Modules\Content\Application\Command\ScheduleBanner\ScheduleBannerHandler;
use Modules\Content\Application\Command\ScheduleCampaign\ScheduleCampaign;
use Modules\Content\Application\Command\ScheduleCampaign\ScheduleCampaignHandler;
use Modules\Content\Application\Command\SetRunPrices\SetRunPrices;
use Modules\Content\Application\Command\SetRunPrices\SetRunPricesHandler;
use Modules\Content\Application\Command\ShowHomeSection\ShowHomeSection;
use Modules\Content\Application\Command\ShowHomeSection\ShowHomeSectionHandler;
use Modules\Content\Application\ContentCache;
use Modules\Content\Application\Documents\DocumentInput;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Content\Support\ContentBrowser as Cb;
use Tests\Modules\Content\Support\ContentFixtures as Cx;

use function Pest\Laravel\seed;

/*
| The shop's own Content pages in a real browser (content.md §4.5, H1-H7; plan PR 10): each at 375 x 812 in both
| languages, nothing sideways and every digit 0-9, and brand.com/ with and without a remembered store.
|
| Deliberately no RefreshDatabase (the browser suite's way): each run writes its own items in `eg`, their addresses made
| unique by the run's clock, so runs never meet (lesson 174).
*/

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    // The runs before keep their rows: the catalogue's names start from the clock, so each is new.
    Px::numberFrom((int) substr((string) hrtime(true), -8));
});

/**
 * One of each of Content's pages in `eg`, published or live, and the home's sections shown; their addresses by page.
 *
 * @return array<string, string> page => its path under /eg/{locale}
 */
function contentShopBrowserItems(): array
{
    $n = (string) hrtime(true);
    $store = Fx::storeId('eg');

    return Fx::asSystem(function () use ($n, $store): array {
        $p = Px::ready(['60 cm'], Px::category('Kitchens'));
        app(ChooseInStoreHandler::class)->handle(new ChooseInStore($store, $p['product'], true));
        Px::onSale('eg', $p['product']);

        foreach (['BEST_SELLERS' => ['heading' => ['ar' => 'الأكثر طلبًا', 'en' => 'Best Sellers'], 'count' => 8], 'CATEGORIES' => ['heading' => ['ar' => 'تسوق حسب الفئة', 'en' => 'Shop by Category'], 'count' => 8], 'PROJECTS' => ['heading' => ['ar' => 'مشاريع نفذت بقطعنا', 'en' => 'Projects Built With Our Parts']]] as $type => $settings) {
            $section = DB::table('content.home_sections')->where('store_id', $store)->where('type', $type)->first() ?? throw new LogicException("No {$type}.");
            app(FillHomeSectionHandler::class)->handle(new FillHomeSection((string) $section->id, (int) $section->version, $settings, []));
            app(ShowHomeSectionHandler::class)->handle(new ShowHomeSection((string) $section->id, true));
        }

        $page = app(SavePageHandler::class)->handle(new SavePage(new DocumentInput(id: null, storeId: $store, version: 0, titleAr: "سياسة الإرجاع {$n}", titleEn: "Returns Policy {$n}", slugAr: "الإرجاع-{$n}", slugEn: "returns-{$n}", bodyAr: "# الإرجاع\n\nيمكنك إرجاع القطعة خلال 14 يومًا.\n\n| المدة | الحالة |\n| 14 يومًا | بحالتها الأصلية |", bodyEn: "# Returns\n\nYou may return a piece within 14 days.\n\n| Period | Condition |\n| 14 days | As it came |")));
        app(PublishPageHandler::class)->handle(new PublishPage($page, 1));

        $post = app(SavePostHandler::class)->handle(new SavePost(new DocumentInput(
            id: null, storeId: $store, version: 0, titleAr: "كيف تختار مجرى الدرج {$n}", titleEn: "How to Choose a Drawer Slide {$n}", slugAr: "مجرى-الدرج-{$n}", slugEn: "drawer-slide-{$n}",
            bodyAr: 'اختر المجرى حسب عمق الدرج ووزنه.', bodyEn: 'Choose the slide by the drawer\'s depth and weight.',
            summaryAr: 'دليل قصير', summaryEn: 'A short guide', coverMediaId: Cx::image(), coverAltAr: 'درج', coverAltEn: 'A drawer', products: [$p['product']],
        )));
        app(PublishPostHandler::class)->handle(new PublishPost($post, 1));

        $project = app(SaveProjectHandler::class)->handle(new SaveProject(new DocumentInput(
            id: null, storeId: $store, version: 0, titleAr: "غرفة ملابس كاملة {$n}", titleEn: "A Whole Dressing Room {$n}", slugAr: "غرفة-ملابس-{$n}", slugEn: "dressing-room-{$n}",
            bodyAr: 'أدراج أحذية وعلاقات هيدروليك وإضاءة داخلية.', bodyEn: 'Shoe drawers, hydraulic hangers and inside lighting.', cityAr: 'القاهرة', cityEn: 'Cairo', photos: array_map(static fn (): string => Cx::image(), range(1, 5)), products: [$p['product']],
            // S4.1: the client, so the chips, the client box and the cards' "With ..." are drawn.
            clientNameAr: 'شركة الأفق', clientNameEn: 'Horizon Co', clientLogoMediaId: Cx::image(), clientLink: 'https://horizon.example/work',
        )));
        app(PublishProjectHandler::class)->handle(new PublishProject($project, 1));

        $campaign = app(SaveCampaignHandler::class)->handle(new SaveCampaign(null, 0, "عروض الشتاء {$n}", "Winter Offers {$n}", Cx::typed(1, 'eg'), Cx::typed(60 * 24 * 5, 'eg'), [$store]));
        $run = (string) DB::table('content.campaign_runs')->where('campaign_id', $campaign)->value('id');
        $version = static fn (): int => (int) DB::table('content.campaigns')->where('id', $campaign)->value('version');
        app(SetRunPricesHandler::class)->handle(new SetRunPrices($run, $version(), ['lines' => 'BOTH', 'targets' => [['kind' => 'PRODUCT', 'id' => $p['product'], 'percentBp' => 1000]]]));
        app(ScheduleCampaignHandler::class)->handle(new ScheduleCampaign($campaign, $version()));
        // Live at once: its start a minute ahead, moved behind now (the browser's server reads the real clock).
        DB::table('content.campaign_runs')->where('id', $run)->update(['starts_at' => now()->subMinute()]);
        app(AdvanceCampaignsHandler::class)->handle(new AdvanceCampaigns);
        app(ContentCache::class)->invalidate();

        return [
            'home' => '',
            'page' => "/pages/returns-{$n}",
            'blog' => '/blog',
            'post' => "/blog/drawer-slide-{$n}",
            'projects' => '/projects',
            'project' => "/projects/dressing-room-{$n}",
            'campaign' => '/campaigns/'.DB::table('content.campaign_runs')->where('id', $run)->value('slug_en'),
        ];
    });
}

it('draws every Content page at 375 x 812 in both languages, nothing sideways, every digit 0-9', function () {
    $paths = contentShopBrowserItems();
    $marks = ['home' => 'home', 'page' => 'content-page', 'blog' => 'blog', 'post' => 'content-post', 'projects' => 'projects', 'project' => 'content-project', 'campaign' => 'campaign'];
    $page = visit('/eg/en');
    $page->resize(375, 812);

    foreach (['en', 'ar'] as $locale) {
        foreach ($paths as $name => $path) {
            $address = "/eg/{$locale}{$path}";
            // The English address under Arabic forwards to the Arabic one, which is the page asked for.
            $page->navigate($address, BROWSER_PAGE_LOAD);

            expect(browserSoon($page, "document.querySelector('[data-test=\"{$marks[$name]}\"]') !== null"))->toBeTrue("{$address}: not drawn")
                ->and($page->script('document.documentElement.lang'))->toBe($locale)
                ->and(Cb::fitsPhone($page))->toBeTrue("{$address}: ".Cb::overflow($page))
                ->and(Cb::latinDigits($page))->toBeTrue("{$address}: a digit not 0-9");
        }
    }

    // The products as the shop's cards, the swiped photos, a body's table inside its own frame.
    $page->navigate("/eg/en{$paths['post']}", BROWSER_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelectorAll('[data-test=\"content-products\"] [data-test=\"card\"]').length === 1"))->toBeTrue();
    $page->navigate("/eg/en{$paths['project']}", BROWSER_PAGE_LOAD);
    // The gallery: the first photo, two beside it stacked (the third carrying "+2"), and the phone's strip of the other four.
    expect(browserSoon($page, "document.querySelector('[data-test=\"gallery-main\"] img') !== null && document.querySelectorAll('[data-test=\"gallery-strip\"] button').length === 4"))->toBeTrue()
        ->and($page->script("document.querySelector('[data-test=\"gallery-stack\"]') !== null && getComputedStyle(document.querySelector('[data-test=\"gallery-stack\"]')).display === 'none'"))->toBeTrue('the stack is the wide layout\'s: hidden on a phone')
        ->and($page->script("getComputedStyle(document.querySelector('[data-test=\"gallery-strip\"]')).display !== 'none'"))->toBeTrue('the strip is the phone\'s')
        ->and($page->script("document.querySelector('[data-test=\"chip-client\"]').textContent.trim()"))->toBe('With Horizon Co')
        ->and($page->script("document.querySelector('[data-test=\"chip-link\"]').textContent.trim() + ' ' + document.querySelector('[data-test=\"chip-link\"]').getAttribute('href') + ' ' + document.querySelector('[data-test=\"chip-link\"]').getAttribute('rel')"))->toBe('Client\'s site https://horizon.example/work noopener noreferrer')
        ->and($page->script("document.querySelector('[data-test=\"client-box\"] [data-test=\"client-link\"]') !== null && document.querySelector('[data-test=\"client-box\"]').textContent.includes('Horizon Co')"))->toBeTrue();
    $page->navigate("/eg/en{$paths['home']}", BROWSER_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"home-best-sellers\"] [data-test=\"card\"]') !== null && document.querySelector('[data-test=\"home-projects\"]') !== null && document.querySelector('[data-test=\"home-tile\"]') !== null"))->toBeTrue();

    // The project's caption is under the photo, which has no overlay and no gradient (S4.2): the title at 16 px, then the city
    // and the client in the muted ink, readable on the page's ground in both modes.
    $dark = visit('/eg/en')->inDarkMode();
    $dark->resize(375, 812);
    expect(browserSoon($dark, "document.querySelector('[data-test=\"home-project-caption\"]') !== null"))->toBeTrue();
    foreach (['light' => $page, 'dark' => $dark] as $mode => $shown) {
        expect($shown->script("(() => { const link = document.querySelector('[data-test=\"home-project\"]'); const photo = link.querySelector('img, [aria-hidden]'); const caption = link.querySelector('[data-test=\"home-project-caption\"]'); const title = caption.firstElementChild; return caption.getBoundingClientRect().top >= photo.getBoundingClientRect().bottom - 1 && getComputedStyle(link).backgroundImage === 'none' && getComputedStyle(caption).backgroundImage === 'none' && getComputedStyle(title).fontSize === '16px'; })()"))->toBeTrue("{$mode}: the caption is not under a bare photo with its title at 16 px")
            ->and($shown->script("document.querySelector('[data-test=\"home-project-caption\"]').textContent.includes('With Horizon Co') && document.querySelector('[data-test=\"home-project-caption\"]').textContent.includes('Cairo')"))->toBeTrue("{$mode}: the city and the client are not under the title");

        foreach (['first-child' => 'title', 'last-child' => 'line'] as $child => $part) {
            expect(Cb::contrast($shown, "[data-test=\"home-project-caption\"] span:{$child}", 'body'))->toBeGreaterThanOrEqual(4.5, "{$mode}: the project's {$part} does not read");
        }
    }

    // The projects list: the same caption, under each photo.
    $page->navigate("/eg/en{$paths['projects']}", BROWSER_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"project-card\"]') !== null"))->toBeTrue()
        ->and($page->script("(() => { const card = document.querySelector('[data-test=\"project-card\"]'); const photo = card.querySelector('img, [aria-hidden]'); const caption = card.querySelector('[data-test=\"project-caption\"]'); return caption.getBoundingClientRect().top >= photo.getBoundingClientRect().bottom - 1 && getComputedStyle(card).backgroundImage === 'none' && getComputedStyle(caption.firstElementChild).fontSize === '16px' && caption.textContent.includes('Cairo') && caption.textContent.includes('With Horizon Co'); })()"))->toBeTrue('the list\'s caption is not under a bare photo');

    $page->assertNoJavaScriptErrors();
    $dark->assertNoJavaScriptErrors();
});

it('lays a project\'s page out as the gallery, the story and the client box, and opens every photo in the viewer, in both directions', function () {
    $paths = contentShopBrowserItems();

    foreach (['en' => 'ltr', 'ar' => 'rtl'] as $locale => $direction) {
        $page = visit("/eg/{$locale}");
        $page->resize(1280, 900);
        $page->navigate("/eg/{$locale}{$paths['project']}", BROWSER_PAGE_LOAD);
        expect(browserSoon($page, "document.querySelector('[data-test=\"gallery-main\"] img') !== null && document.documentElement.dir === '{$direction}'"))->toBeTrue("{$locale}: not drawn");

        // The first photo about two thirds of the gallery's width, the next two stacked in the third that is left, the
        // client box beside the story in the other column; in Arabic everything the other way round.
        $layout = json_decode((string) $page->script("(() => { const r = (s) => { const e = document.querySelector(s); const b = e.getBoundingClientRect(); return { left: b.left, right: b.right, top: b.top, bottom: b.bottom, width: b.width }; }; const tiles = [...document.querySelectorAll('[data-test=\"gallery-tile\"]')].map((e) => { const b = e.getBoundingClientRect(); return { left: b.left, top: b.top, bottom: b.bottom, width: b.width }; }); return JSON.stringify({ main: r('[data-test=\"gallery-main\"]'), section: r('[data-test=\"project-photos\"]'), tiles, story: r('[data-test=\"project-story\"]'), box: r('[data-test=\"client-box\"]'), strip: getComputedStyle(document.querySelector('[data-test=\"gallery-strip\"]').closest('[data-slot=\"scroll-area\"]')).display, more: document.querySelector('[data-test=\"gallery-more\"]')?.textContent }); })()"), true, 512, JSON_THROW_ON_ERROR);
        $share = $layout['main']['width'] / $layout['section']['width'];

        expect($share)->toBeGreaterThan(0.6)->toBeLessThan(0.72)
            ->and(count($layout['tiles']))->toBe(2)
            ->and($layout['tiles'][0]['left'])->toBe($layout['tiles'][1]['left'])
            ->and($layout['tiles'][0]['bottom'])->toBeLessThanOrEqual($layout['tiles'][1]['top'] + 1)
            ->and($layout['more'])->toBe('+2')
            ->and($layout['strip'])->toBe('none');

        if ($direction === 'ltr') {
            expect($layout['tiles'][0]['left'])->toBeGreaterThanOrEqual($layout['main']['right'])
                ->and($layout['box']['left'])->toBeGreaterThanOrEqual($layout['story']['right']);
        } else {
            expect($layout['tiles'][0]['left'] + $layout['tiles'][0]['width'])->toBeLessThanOrEqual($layout['main']['left'] + 1)
                ->and($layout['box']['right'])->toBeLessThanOrEqual($layout['story']['left'] + 1);
        }

        // Every photo opens in the viewer, which counts them and moves on with its arrows; Escape closes it.
        $page->click('[data-test="gallery-main"]');
        expect(browserSoon($page, "document.querySelector('[data-test=\"photo-viewer\"] [data-test=\"viewer-count\"]')?.textContent.trim() === '".($locale === 'en' ? '1 of 5' : '1 من 5')."'"))->toBeTrue("{$locale}: the viewer did not open on the first photo");
        $page->click('[data-test="viewer-next"]');
        expect(browserSoon($page, "document.querySelector('[data-test=\"viewer-count\"]')?.textContent.trim() === '".($locale === 'en' ? '2 of 5' : '2 من 5')."'"))->toBeTrue("{$locale}: Next did not move on")
            ->and(Cb::latinDigits($page))->toBeTrue();
        // The arrow keys follow the page's direction: the right one is Next in English, the left one in Arabic.
        [$next, $previous] = $direction === 'ltr' ? ['ArrowRight', 'ArrowLeft'] : ['ArrowLeft', 'ArrowRight'];
        $page->keys('[data-test="photo-viewer"]', $next);
        expect(browserSoon($page, "document.querySelector('[data-test=\"viewer-count\"]')?.textContent.trim() === '".($locale === 'en' ? '3 of 5' : '3 من 5')."'"))->toBeTrue("{$locale}: the {$next} key did not go to the next photo");
        $page->keys('[data-test="photo-viewer"]', $previous);
        expect(browserSoon($page, "document.querySelector('[data-test=\"viewer-count\"]')?.textContent.trim() === '".($locale === 'en' ? '2 of 5' : '2 من 5')."'"))->toBeTrue("{$locale}: the {$previous} key did not go back")
            // Close is in the page's language.
            ->and($page->script("document.querySelector('[data-test=\"viewer-close\"]').getAttribute('aria-label')"))->toBe($locale === 'en' ? 'Close' : 'إغلاق');
        $page->keys('[data-test="photo-viewer"]', 'Escape');
        expect(browserSoon($page, "document.querySelector('[data-test=\"photo-viewer\"]') === null"))->toBeTrue("{$locale}: Escape did not close the viewer")
            // Focus goes back to the tile that opened it.
            ->and(browserSoon($page, "document.activeElement?.getAttribute('data-test') === 'gallery-main'"))->toBeTrue("{$locale}: focus did not return to the photo that opened the viewer");
        $page->click('[data-test="gallery-stack"] li:first-child button');
        expect(browserSoon($page, "document.querySelector('[data-test=\"viewer-count\"]')?.textContent.trim() === '".($locale === 'en' ? '2 of 5' : '2 من 5')."'"))->toBeTrue("{$locale}: a stacked photo did not open at its own place");

        $page->assertNoJavaScriptErrors();
    }
});

it('draws a project with no client with no client box, chip or caption line, one photo large and no strip', function () {
    $paths = contentShopBrowserItems();
    $slug = substr($paths['project'], strlen('/projects/'));
    $id = (string) DB::table('content.project_translations')->where('slug', $slug)->value('project_id');
    DB::table('content.projects')->where('id', $id)->update(['client_logo_media' => null, 'client_link' => null]);
    DB::table('content.project_translations')->where('project_id', $id)->update(['client_name' => null]);
    DB::table('content.project_photos')->where('project_id', $id)->where('position', '>', 0)->delete();
    app(ContentCache::class)->invalidate();

    $page = visit('/eg/en');
    $page->resize(375, 812);
    $page->navigate("/eg/en{$paths['project']}", BROWSER_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"gallery-main\"] img') !== null"))->toBeTrue()
        ->and($page->script("['client-box', 'chip-client', 'chip-link', 'gallery-strip', 'gallery-stack'].filter((name) => document.querySelector('[data-test=\"' + name + '\"]') !== null)"))->toBe([])
        ->and($page->script("document.querySelector('[data-test=\"chip-city\"]').textContent.trim()"))->toBe('Cairo')
        ->and($page->script("document.querySelector('[data-test=\"project-columns\"]').className.includes('lg:grid-cols')"))->toBeFalse()
        ->and(Cb::fitsPhone($page))->toBeTrue(Cb::overflow($page));
    $page->assertNoJavaScriptErrors();
});

it('keeps a 100-character client name inside the phone on the project\'s page, the list and the home, in both languages', function () {
    $paths = contentShopBrowserItems();
    $slug = substr($paths['project'], strlen('/projects/'));
    $id = (string) DB::table('content.project_translations')->where('slug', $slug)->value('project_id');
    DB::table('content.project_translations')->where('project_id', $id)->where('locale', 'en')->update(['client_name' => str_repeat('W', 100)]);
    DB::table('content.project_translations')->where('project_id', $id)->where('locale', 'ar')->update(['client_name' => str_repeat('ع', 100)]);
    app(ContentCache::class)->invalidate();

    $page = visit('/eg/en');
    $page->resize(375, 812);

    foreach (['en', 'ar'] as $locale) {
        foreach (['project' => $paths['project'], 'projects' => '/projects', 'home' => ''] as $name => $path) {
            $page->navigate("/eg/{$locale}{$path}", BROWSER_PAGE_LOAD);
            expect(browserSoon($page, "document.body.textContent.includes('".($locale === 'en' ? str_repeat('W', 100) : str_repeat('ع', 100))."')"))->toBeTrue("{$locale} {$name}: the name is not drawn")
                ->and(Cb::fitsPhone($page))->toBeTrue("{$locale} {$name}: ".Cb::overflow($page));
        }
    }

    $page->assertNoJavaScriptErrors();
});

it('sends brand.com/ to the base store\'s Arabic home with no store remembered, and to the remembered one with it', function () {
    $page = visit('/');
    $page->assertPathIs('/sa/ar');

    // A store visited is remembered (its cookie): brand.com/ goes back to it, in its language.
    $page->navigate('/eg/en', BROWSER_PAGE_LOAD);
    $page->navigate('/', BROWSER_PAGE_LOAD);
    $page->assertPathIs('/eg/en')->assertNoJavaScriptErrors();
});

it('draws a stored link outside the rules as its plain words, never as a link', function () {
    $paths = contentShopBrowserItems();
    $slug = substr($paths['page'], strlen('/pages/'));
    // Written past the editor, which refuses it (§1.1 rule 16): what the shop does with one that reached a body anyway.
    DB::table('content.page_translations')->where('locale', 'en')->where('slug', $slug)->update(['body' => json_encode(['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'A link that is not one', 'link' => 'javascript:alert(1)'], ['text' => ' and one that is', 'link' => '/pages/returns']]]]])]);

    $page = visit("/eg/en{$paths['page']}");

    expect(browserSoon($page, "document.querySelector('[data-test=\"content-page\"]')?.textContent.includes('A link that is not one') === true"))->toBeTrue()
        ->and($page->script("document.querySelectorAll('[data-test=\"content-page\"] a[href^=\"javascript\"]').length"))->toBe(0)
        ->and($page->script("document.querySelector('[data-test=\"content-page\"] a[href=\"/eg/en/pages/returns\"]') !== null"))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

it('turns the home banners every 5 seconds with their words, the arrows and dots choosing one, the title under them', function () {
    $store = Fx::storeId('eg');
    // This run's banners alone: the ones runs before left behind go (the browser suite keeps its rows, lesson 174).
    DB::table('content.banners')->where('store_id', $store)->where('placement', 'HOME_HERO')->delete();

    Fx::asSystem(function () use ($store): void {
        $hero = DB::table('content.home_sections')->where('store_id', $store)->where('type', 'HERO')->first() ?? throw new LogicException('No HERO.');
        app(FillHomeSectionHandler::class)->handle(new FillHomeSection((string) $hero->id, (int) $hero->version, ['title' => ['ar' => 'إكسسوارات المطابخ', 'en' => 'Kitchen hardware']], []));
        app(ShowHomeSectionHandler::class)->handle(new ShowHomeSection((string) $hero->id, true));

        foreach (['A', 'B'] as $name) {
            $banner = Cx::banner(['name' => "Banner {$name}", 'words' => ['title_ar' => "عرض {$name}", 'title_en' => "Offer {$name}", 'button_ar' => 'تسوّق', 'button_en' => 'Shop Now']], 'eg');
            app(ScheduleBannerHandler::class)->handle(new ScheduleBanner($banner, 1));
        }
    });
    app(ContentCache::class)->invalidate();

    // shop-design.md §3.1: the carousel comes after the page shows; the store's title stays the page's H1 under it.
    $page = visit('/eg/en');
    expect(browserSoon($page, "document.querySelector('[data-test=\"home-banners\"]')?.dataset.turning === 'true'"))->toBeTrue('the carousel never came')
        ->and($page->script("document.querySelector('h1[data-test=\"home-title\"]')?.textContent"))->toBe('Kitchen hardware')
        ->and($page->script("document.querySelectorAll('[data-test=\"banner-dots\"] button').length"))->toBe(2)
        ->and($page->script("document.querySelector('[data-test=\"home-banner-words\"]')?.textContent"))->toContain('Offer A')
        // One picture a banner, 3:1 at a computer's width (no box held for the phone's picture).
        ->and($page->script("(() => { const r = document.querySelector('[data-test=\"home-banner\"] [data-test=\"banner-picture\"]').getBoundingClientRect(); return Math.abs(r.width / r.height - 3) < 0.05 && document.querySelectorAll('[data-test=\"home-banner\"]')[0].querySelectorAll('img').length === 1; })()"))->toBeTrue('the banner is not one 3:1 picture')
        // By itself after 5 seconds, the second dot current.
        ->and(browserSoon($page, "document.querySelectorAll('[data-test=\"banner-dots\"] button')[1]?.getAttribute('aria-current') === 'true'", 9))->toBeTrue('the banners did not turn by themselves');

    // The arrows choose one: back to the first.
    $page->click('[data-test="banner-next"]');
    expect(browserSoon($page, "document.querySelectorAll('[data-test=\"banner-dots\"] button')[0]?.getAttribute('aria-current') === 'true'"))->toBeTrue('the arrow did not move it');

    // A phone: its own picture, nothing sideways, the title still the H1.
    $page->resize(375, 812);
    $page->navigate('/eg/ar', BROWSER_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"home-banners\"]')?.dataset.turning === 'true'"))->toBeTrue()
        ->and($page->script("document.querySelector('h1[data-test=\"home-title\"]')?.textContent"))->toBe('إكسسوارات المطابخ')
        // A phone: the phone's picture, 2:1.
        ->and($page->script("(() => { const r = document.querySelector('[data-test=\"home-banner\"] [data-test=\"banner-picture\"]').getBoundingClientRect(); return Math.abs(r.width / r.height - 2) < 0.05; })()"))->toBeTrue('the phone banner is not 2:1')
        ->and(Cb::fitsPhone($page))->toBeTrue(Cb::overflow($page));
    $page->assertNoJavaScriptErrors();
});
