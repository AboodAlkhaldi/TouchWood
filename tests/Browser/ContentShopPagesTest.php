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
            bodyAr: 'أدراج أحذية وعلاقات هيدروليك وإضاءة داخلية.', bodyEn: 'Shoe drawers, hydraulic hangers and inside lighting.', cityAr: 'القاهرة', cityEn: 'Cairo', photos: [Cx::image(), Cx::image()], products: [$p['product']],
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
    expect(browserSoon($page, "document.querySelectorAll('[data-test=\"project-photos\"] img').length === 2"))->toBeTrue();
    $page->navigate("/eg/en{$paths['home']}", BROWSER_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test=\"home-best-sellers\"] [data-test=\"card\"]') !== null && document.querySelector('[data-test=\"home-projects\"]') !== null && document.querySelector('[data-test=\"home-tile\"]') !== null"))->toBeTrue();

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
