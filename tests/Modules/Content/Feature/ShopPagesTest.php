<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Catalog\Application\Command\SetCategoryAudiences\SetCategoryAudiences;
use Modules\Catalog\Application\Command\SetCategoryAudiences\SetCategoryAudiencesHandler;
use Modules\Catalog\Application\Command\SetSellingTerms\SetSellingTerms;
use Modules\Catalog\Application\Command\SetSellingTerms\SetSellingTermsHandler;
use Modules\Content\Application\Command\AdvanceCampaigns\AdvanceCampaigns;
use Modules\Content\Application\Command\AdvanceCampaigns\AdvanceCampaignsHandler;
use Modules\Content\Application\Command\CancelCampaign\CancelCampaign;
use Modules\Content\Application\Command\CancelCampaign\CancelCampaignHandler;
use Modules\Content\Application\Command\EndCampaign\EndCampaign;
use Modules\Content\Application\Command\EndCampaign\EndCampaignHandler;
use Modules\Content\Application\Command\FillHomeSection\FillHomeSection;
use Modules\Content\Application\Command\FillHomeSection\FillHomeSectionHandler;
use Modules\Content\Application\Command\PublishPage\PublishPage;
use Modules\Content\Application\Command\PublishPage\PublishPageHandler;
use Modules\Content\Application\Command\PublishPost\PublishPost;
use Modules\Content\Application\Command\PublishPost\PublishPostHandler;
use Modules\Content\Application\Command\PublishProject\PublishProject;
use Modules\Content\Application\Command\PublishProject\PublishProjectHandler;
use Modules\Content\Application\Command\SaveBlogCategory\SaveBlogCategory;
use Modules\Content\Application\Command\SaveBlogCategory\SaveBlogCategoryHandler;
use Modules\Content\Application\Command\SaveCampaign\SaveCampaign;
use Modules\Content\Application\Command\SaveCampaign\SaveCampaignHandler;
use Modules\Content\Application\Command\SavePage\SavePage;
use Modules\Content\Application\Command\SavePage\SavePageHandler;
use Modules\Content\Application\Command\SavePost\SavePost;
use Modules\Content\Application\Command\SavePost\SavePostHandler;
use Modules\Content\Application\Command\SaveProject\SaveProject;
use Modules\Content\Application\Command\SaveProject\SaveProjectHandler;
use Modules\Content\Application\Command\SaveSeoDefaults\SaveSeoDefaults;
use Modules\Content\Application\Command\SaveSeoDefaults\SaveSeoDefaultsHandler;
use Modules\Content\Application\Command\ScheduleCampaign\ScheduleCampaign;
use Modules\Content\Application\Command\ScheduleCampaign\ScheduleCampaignHandler;
use Modules\Content\Application\Command\SetRunPrices\SetRunPrices;
use Modules\Content\Application\Command\SetRunPrices\SetRunPricesHandler;
use Modules\Content\Application\Command\ShowHomeSection\ShowHomeSection;
use Modules\Content\Application\Command\ShowHomeSection\ShowHomeSectionHandler;
use Modules\Content\Application\Command\UnpublishPage\UnpublishPage;
use Modules\Content\Application\Command\UnpublishPage\UnpublishPageHandler;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Application\Documents\DocumentInput;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSetting;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSettingHandler;
use Modules\Platform\Public\Contracts\ShopperType;
use Modules\Platform\Public\Enums\ShopperKind;
use Modules\Pricing\Application\Command\SetWholesalePrices\SetWholesalePrices;
use Modules\Pricing\Application\Command\SetWholesalePrices\SetWholesalePricesHandler;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Content\Support\ContentFixtures as Cx;
use Tests\Modules\Pricing\Support\PricingFixtures as Rx;

use function Pest\Laravel\get;
use function Pest\Laravel\seed;

/*
| The shop's own Content pages over HTTP (content.md §4.5, H1-H7; plan PR 10): each page's canonical address and its
| other language's, noindex where it is kept out, a draft's 404, the slug history's 301s only when the page answers
| 200, an off store's 404, and the queries each asks, warm. Every helper here is named after this file's subject: a Pest
| file's functions are global.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-11-11 09:00:00+00'));
});

afterEach(function () {
    CarbonImmutable::setTestNow();
    Carbon\Carbon::setTestNow();
});

/**
 * A shop page's props, as Inertia hands them; the page a fresh request.
 *
 * @return array<string, mixed>
 */
function contentShopPage(string $url, string $component): array
{
    app()->forgetScopedInstances();
    $props = [];
    get($url)->assertOk()->assertInertia(function (AssertableInertia $page) use (&$props, $component): void {
        $page->component($component);
        $props = $page->toArray()['props'];
    });

    return $props;
}

/** How many queries a page asks, warm: once to fill the caches, then counted as a new request (as Catalog's ShopPagesTest). */
function contentShopQueries(string $url): int
{
    app()->forgetScopedInstances();
    get($url)->assertOk();
    app()->forgetScopedInstances();
    DB::flushQueryLog();
    DB::enableQueryLog();
    get($url)->assertOk();
    DB::disableQueryLog();

    return count(DB::getQueryLog());
}

/** The status and Location a fresh request answers. */
function contentShopAnswer(string $url): string
{
    app()->forgetScopedInstances();
    $response = get($url);

    return $response->getStatusCode().' '.($response->headers->get('Location') ?? '');
}

/**
 * A published page, as its editor, with these words.
 *
 * @param  array<string, mixed>  $with
 */
function contentShopPublishedPage(array $with = [], string $code = 'sa'): string
{
    Cx::editor([ContentPermissions::PAGE_MANAGE], [$code]);
    $page = Cx::page(['slugAr' => 'من-نحن', 'slugEn' => 'about-us', ...$with], $code);
    app(PublishPageHandler::class)->handle(new PublishPage($page, 1));

    return $page;
}

/**
 * A published post, its cover ready, linking these products.
 *
 * @param  list<string>  $products
 */
function contentShopPost(string $titleEn = 'Choosing a drawer slide', array $products = [], ?string $categoryId = null): string
{
    Cx::editor([ContentPermissions::BLOG_MANAGE]);
    $post = app(SavePostHandler::class)->handle(new SavePost(new DocumentInput(
        id: null, storeId: Fx::storeId('sa'), version: 0, titleAr: 'اختيار مجرى الدرج '.$titleEn, titleEn: $titleEn, bodyAr: 'نص المقال', bodyEn: 'The post text',
        summaryAr: 'ملخص', summaryEn: 'A summary', coverMediaId: Cx::image(), coverAltAr: 'درج', coverAltEn: 'A drawer', categoryId: $categoryId, products: $products,
    )));
    app(PublishPostHandler::class)->handle(new PublishPost($post, 1));

    return $post;
}

/** A published project, its one photo ready. */
function contentShopProject(): string
{
    Cx::editor([ContentPermissions::PROJECT_MANAGE]);
    $project = app(SaveProjectHandler::class)->handle(new SaveProject(new DocumentInput(
        id: null, storeId: Fx::storeId('sa'), version: 0, titleAr: 'غرفة ملابس', titleEn: 'A dressing room', slugAr: 'غرفة-ملابس', slugEn: 'dressing-room', bodyAr: 'القصة', bodyEn: 'The story',
        cityAr: 'جدة', cityEn: 'Jeddah', photos: [Cx::image()],
    )));
    app(PublishProjectHandler::class)->handle(new PublishProject($project, 1));

    return $project;
}

/**
 * A ready product on sale in the store.
 *
 * @return array{product: string, variants: list<string>, width: string}
 */
function contentShopProduct(): array
{
    $p = Px::ready(['60 cm'], Px::category('Kitchens'));
    Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId('sa'), $p['product'], true)));
    Px::onSale('sa', $p['product']);

    return $p;
}

describe('H2 a page (§1.11, §1.1 rule 15)', function () {
    it('shows a published page with its canonical address, the other language\'s, and its head', function () {
        contentShopPublishedPage();

        $en = contentShopPage('/sa/en/pages/about-us', 'Content/Storefront/Document');
        $ar = contentShopPage('/sa/ar/pages/'.rawurlencode('من-نحن'), 'Content/Storefront/Document');

        expect($en['kind'])->toBe('page')
            ->and($en['address']['canonical'])->toBe(url('/sa/en/pages/about-us'))
            ->and($en['address']['alternate'])->toBe(url('/sa/ar/pages/'.rawurlencode('من-نحن')))
            ->and($en['address']['alternateLocale'])->toBe('ar')
            ->and($en['address']['noindex'])->toBeFalse()
            ->and($en['address']['alternates'])->toBe([])
            ->and($en['blocks'][0]['runs'][0]['text'])->toBe('The page text')
            ->and($en['head']['title'])->toStartWith('Page ')
            ->and($ar['address']['alternate'])->toBe(url('/sa/en/pages/about-us'))
            ->and($ar['blocks'][0]['runs'][0]['text'])->toBe('نص الصفحة');
    });

    it('answers 404 for a draft, a page of another store and an off store, as for an address nobody held', function () {
        Cx::editor([ContentPermissions::PAGE_MANAGE]);
        Cx::page(['slugEn' => 'draft-page']);
        contentShopPublishedPage(['slugAr' => 'صفحة-الإمارات', 'slugEn' => 'emirates-page'], 'ae');

        expect(contentShopAnswer('/sa/en/pages/draft-page'))->toBe('404 ')
            ->and(contentShopAnswer('/sa/en/pages/emirates-page'))->toBe('404 ')
            ->and(contentShopAnswer('/sa/en/pages/never-was'))->toBe('404 ')
            ->and(contentShopAnswer('/ae/en/pages/emirates-page'))->toStartWith('200');

        Cx::switchOff('ae');

        expect(contentShopAnswer('/ae/en/pages/emirates-page'))->toBe('404 ');
    });

    it('forwards an old address and the other language\'s in one 301 - only while the page answers 200', function () {
        $page = contentShopPublishedPage();
        Cx::editor([ContentPermissions::PAGE_MANAGE]);
        app(SavePageHandler::class)->handle(new SavePage(new DocumentInput(id: $page, storeId: null, version: 2, titleAr: 'صفحة', titleEn: 'Page', slugAr: 'من-نحن', slugEn: 'who-we-are', bodyAr: 'نص الصفحة', bodyEn: 'The page text')));

        expect(contentShopAnswer('/sa/en/pages/about-us?utm_source=ad'))->toBe('301 '.url('/sa/en/pages/who-we-are?utm_source=ad'))
            // The Arabic address under the English language: to the English one.
            ->and(contentShopAnswer('/sa/en/pages/'.rawurlencode('من-نحن')))->toBe('301 '.url('/sa/en/pages/who-we-are'))
            ->and(contentShopAnswer('/sa/ar/pages/who-we-are'))->toBe('301 '.url('/sa/ar/pages/'.rawurlencode('من-نحن')));

        app()->forgetScopedInstances();
        expect(get('/sa/en/pages/'.rawurlencode('من-نحن'))->headers->get('Cache-Control'))->toContain('no-cache');

        // Unpublished: its addresses, old and current, answer as if nobody had held them (a draft stays hidden).
        app(UnpublishPageHandler::class)->handle(new UnpublishPage($page, 3));

        expect(contentShopAnswer('/sa/en/pages/about-us'))->toBe('404 ')
            ->and(contentShopAnswer('/sa/en/pages/who-we-are'))->toBe('404 ');
    });
});

describe('H3 the blog and H4 a post (§1.12)', function () {
    it('lists published posts newest first with the categories holding one, 12 then Show More', function () {
        Cx::editor([ContentPermissions::BLOG_MANAGE]);
        $category = app(SaveBlogCategoryHandler::class)->handle(new SaveBlogCategory(null, Fx::storeId('sa'), 'أدلة', 'Guides', 'أدلة', 'guides'));
        app(SaveBlogCategoryHandler::class)->handle(new SaveBlogCategory(null, Fx::storeId('sa'), 'فارغ', 'Empty', 'فارغ', 'empty'));

        foreach (range(1, 13) as $n) {
            CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinute());
            contentShopPost("Post {$n}", categoryId: $n === 13 ? $category : null);
        }

        $first = contentShopPage('/sa/en/blog', 'Content/Storefront/Blog');

        expect(array_column($first['posts'], 'title'))->toBe(array_map(static fn (int $n): string => "Post {$n}", range(13, 2)))
            ->and($first['categories'])->toBe([['name' => 'Guides', 'slug' => 'guides']])
            ->and($first['next'])->not->toBeNull()
            ->and($first['address']['canonical'])->toBe(url('/sa/en/blog'))
            ->and($first['address']['alternate'])->toBe(url('/sa/ar/blog'))
            ->and($first['address']['noindex'])->toBeFalse();

        $second = contentShopPage('/sa/en/blog?after='.$first['next'], 'Content/Storefront/Blog');

        expect(array_column($second['posts'], 'title'))->toBe(['Post 1'])
            ->and($second['next'])->toBeNull()
            ->and($second['address']['noindex'])->toBeTrue()
            ->and(contentShopAnswer('/sa/en/blog?after=not-a-cursor'))->toBe('404 ');

        $guides = contentShopPage('/sa/en/blog/categories/guides', 'Content/Storefront/Blog');

        expect(array_column($guides['posts'], 'title'))->toBe(['Post 13'])
            ->and($guides['categoryName'])->toBe('Guides')
            ->and($guides['address']['alternate'])->toBe(url('/sa/ar/blog/categories/'.rawurlencode('أدلة')))
            // A category holding no published post has no page.
            ->and(contentShopAnswer('/sa/en/blog/categories/empty'))->toBe('404 ');
    });

    it('says "No posts yet." on an empty blog, kept out of search engines', function () {
        $blog = contentShopPage('/sa/en/blog', 'Content/Storefront/Blog');

        expect($blog['posts'])->toBe([])->and($blog['address']['noindex'])->toBeTrue();
    });

    it('shows a post\'s date, cover and linked products as the shop\'s cards, a product no longer sold left out', function () {
        $sold = contentShopProduct();
        $gone = contentShopProduct();
        $post = contentShopPost(products: [$gone['product'], $sold['product']]);
        Px::onSale('sa', $gone['product'], 0);
        $slug = (string) DB::table('content.blog_post_translations')->where('post_id', $post)->where('locale', 'en')->value('slug');

        $page = contentShopPage("/sa/en/blog/{$slug}", 'Content/Storefront/Document');

        expect($page['kind'])->toBe('post')
            ->and($page['publishedAt'])->toBe(CarbonImmutable::now()->format(DATE_ATOM))
            ->and($page['cover'])->not->toBeNull()
            ->and($page['coverAlt'])->toBe('A drawer')
            ->and(array_column($page['products'], 'productId'))->toBe([$sold['product']])
            ->and($page['cardFacts'])->toBeArray()
            ->and($page['head']['description'])->toBe('A summary')
            ->and($page['head']['image'])->not->toBeNull();
    });
});

describe('H5 the projects and H6 a project (§1.13)', function () {
    it('hides the projects until the first is published, then lists it with its photo and city', function () {
        expect(contentShopAnswer('/sa/en/projects'))->toBe('404 ');

        contentShopProject();

        $list = contentShopPage('/sa/en/projects', 'Content/Storefront/Projects');
        $project = contentShopPage('/sa/en/projects/dressing-room', 'Content/Storefront/Document');

        expect($list['projects'][0]['title'])->toBe('A dressing room')
            ->and($list['projects'][0]['city'])->toBe('Jeddah')
            ->and($list['projects'][0]['photo'])->not->toBeNull()
            ->and($project['kind'])->toBe('project')
            ->and($project['city'])->toBe('Jeddah')
            ->and($project['photos'])->toHaveCount(1)
            ->and($project['address']['alternate'])->toBe(url('/sa/ar/projects/'.rawurlencode('غرفة-ملابس')));
    });
});

/**
 * A campaign of the store from a minute ahead for five days, discounting this product, scheduled.
 *
 * @return array{campaign: string, run: string}
 */
function contentShopCampaign(string $productId, string $nameAr = 'عروض الشتاء', string $nameEn = 'Winter Offers', string $kind = 'PRODUCT'): array
{
    Cx::editor([ContentPermissions::CAMPAIGN_MANAGE, ContentPermissions::PRICING_PRICE_EDIT]);
    $campaign = app(SaveCampaignHandler::class)->handle(new SaveCampaign(null, 0, $nameAr, $nameEn, Cx::typed(1), Cx::typed(60 * 24 * 5), [Fx::storeId('sa')]));
    $run = (string) DB::table('content.campaign_runs')->where('campaign_id', $campaign)->value('id');
    $version = static fn (): int => (int) DB::table('content.campaigns')->where('id', $campaign)->value('version');
    app(SetRunPricesHandler::class)->handle(new SetRunPrices($run, $version(), ['lines' => 'BOTH', 'targets' => [['kind' => $kind, 'id' => $productId, 'percentBp' => 1000]]]));
    app(ScheduleCampaignHandler::class)->handle(new ScheduleCampaign($campaign, $version()));

    return ['campaign' => $campaign, 'run' => $run];
}

describe('H7 a campaign (§4.5, Q5 (a))', function () {
    it('takes its address from its name at Schedule; 404 before going live, its products while live, 301 home after', function () {
        $p = contentShopProduct();
        ['campaign' => $campaign, 'run' => $run] = contentShopCampaign($p['product']);

        expect(DB::table('content.campaign_runs')->where('id', $run)->first(['slug_ar', 'slug_en']))->toEqual((object) ['slug_ar' => 'عروض-الشتاء', 'slug_en' => 'winter-offers'])
            ->and(contentShopAnswer('/sa/en/campaigns/winter-offers'))->toBe('404 ');

        Cx::clockOn(2);
        Fx::asSystem(fn (): int => app(AdvanceCampaignsHandler::class)->handle(new AdvanceCampaigns));

        $page = contentShopPage('/sa/en/campaigns/winter-offers', 'Content/Storefront/Campaign');

        expect($page['name'])->toBe('Winter Offers')
            ->and(array_column($page['products'], 'productId'))->toBe([$p['product']])
            ->and($page['next'])->toBeNull()
            ->and($page['address']['noindex'])->toBeTrue()
            ->and($page['address']['alternate'])->toBe(url('/sa/ar/campaigns/'.rawurlencode('عروض-الشتاء')))
            ->and(contentShopAnswer('/sa/ar/campaigns/winter-offers'))->toBe('301 '.url('/sa/ar/campaigns/'.rawurlencode('عروض-الشتاء')));

        Cx::editor([ContentPermissions::CAMPAIGN_MANAGE]);
        app(EndCampaignHandler::class)->handle(new EndCampaign($campaign));

        expect(contentShopAnswer('/sa/en/campaigns/winter-offers'))->toBe('301 '.url('/sa/en'));
    });
});

describe('H1 the home (§1.4, §1.14)', function () {
    it('names every on store\'s home by language and country, and itself canonical', function () {
        $home = contentShopPage('/sa/en', 'Content/Storefront/Home');
        $stores = DB::table('platform.stores')->where('is_active', true)->orderBy('position')->get(['code', 'country_code']);
        $expected = [];

        foreach ($stores as $store) {
            foreach (['ar', 'en'] as $locale) {
                $expected[] = ['hrefLang' => $locale.'-'.strtoupper((string) $store->country_code), 'href' => url("/{$store->code}/{$locale}")];
            }
        }

        expect($home['address']['canonical'])->toBe(url('/sa/en'))
            ->and($home['address']['alternate'])->toBe(url('/sa/ar'))
            ->and($home['address']['noindex'])->toBeFalse()
            ->and($home['address']['alternates'])->toEqualCanonicalizing($expected)
            // Only shown sections draw; a new store's start hidden (§1.4).
            ->and($home['sections'])->toBe([]);
    });
});

/**
 * Fills a home section of the store and shows it, as its editor.
 *
 * @param  array<string, mixed>  $settings
 */
function contentShopSection(string $type, array $settings): void
{
    Cx::editor([ContentPermissions::HOMEPAGE_MANAGE]);
    $section = DB::table('content.home_sections')->where('store_id', Fx::storeId('sa'))->where('type', $type)->first() ?? throw new LogicException("No {$type}.");
    app(FillHomeSectionHandler::class)->handle(new FillHomeSection((string) $section->id, (int) $section->version, $settings, []));
    app(ShowHomeSectionHandler::class)->handle(new ShowHomeSection((string) $section->id, true));
}

describe('H1 the home\'s sections (§1.4)', function () {
    it('reads best sellers and the category tiles per request, draws the Points figures from the settings, and hides an empty section', function () {
        $p = contentShopProduct();
        contentShopSection('BEST_SELLERS', ['heading' => ['ar' => 'الأكثر مبيعًا', 'en' => 'Best Sellers'], 'count' => 8]);
        contentShopSection('CATEGORIES', ['heading' => ['ar' => 'الفئات', 'en' => 'Categories'], 'count' => 4]);
        contentShopSection('POINTS', ['small_heading' => ['ar' => 'النقاط', 'en' => 'Points'], 'heading' => ['ar' => 'اكسب', 'en' => 'Earn'], 'line' => ['ar' => 'سطر', 'en' => 'A line']]);
        contentShopSection('PROJECTS', ['heading' => ['ar' => 'مشاريع', 'en' => 'Projects']]);

        $home = contentShopPage('/sa/en', 'Content/Storefront/Home');

        // The Points band waits for the programme; the projects for their first.
        expect(array_column($home['sections'], 'type'))->toBe(['CATEGORIES', 'BEST_SELLERS'])
            ->and(array_column($home['bestSellers'], 'productId'))->toBe([$p['product']])
            ->and($home['categoryTiles'])->toBe([['name' => (string) DB::table('catalog.categories')->value('name_en'), 'slug' => (string) DB::table('catalog.category_slugs')->where('locale', 'en')->value('slug'), 'photo' => null]])
            ->and($home['points'])->toBeNull()
            // The phone drawer's counts (catalog.md amendment 46(a)): what the category's page lists for this viewer.
            ->and($home['frame']['catalog']['menu'][0]['products'])->toBe(1);

        Fx::actAsStaff(Fx::staff(superAdmin: true));
        app(UpdateSettingHandler::class)->handle(new UpdateSetting('loyalty.points.enabled', 'sa', true));
        app(UpdateSettingHandler::class)->handle(new UpdateSetting('loyalty.points.points_per_unit_off', 'sa', 20));
        contentShopProject();

        $home = contentShopPage('/sa/en', 'Content/Storefront/Home');

        expect(array_column($home['sections'], 'type'))->toBe(['CATEGORIES', 'BEST_SELLERS', 'PROJECTS', 'POINTS'])
            ->and($home['points'])->toBe(['earn' => 1, 'off' => 20, 'currency' => "\u{20C1}"])
            ->and($home['sections'][2]['project']['title'])->toBe('A dressing room');
    });
});

describe('the queries (owner, 2026-10-19: 8 a shop page is a target, not a cap)', function () {
    it('records each page\'s queries warm, for a guest', function () {
        $p = contentShopProduct();
        contentShopSection('BEST_SELLERS', ['heading' => ['ar' => 'الأكثر مبيعًا', 'en' => 'Best Sellers'], 'count' => 8]);
        contentShopSection('CATEGORIES', ['heading' => ['ar' => 'الفئات', 'en' => 'Categories'], 'count' => 4]);
        contentShopPublishedPage();
        $post = contentShopPost(products: [$p['product']]);
        contentShopProject();
        ['run' => $run] = contentShopCampaign($p['product']);
        Cx::clockOn(2);
        Fx::asSystem(fn (): int => app(AdvanceCampaignsHandler::class)->handle(new AdvanceCampaigns));
        $postSlug = (string) DB::table('content.blog_post_translations')->where('post_id', $post)->where('locale', 'en')->value('slug');

        $counts = array_map(contentShopQueries(...), [
            'home' => '/sa/en',
            'page' => '/sa/en/pages/about-us',
            'blog' => '/sa/en/blog',
            'post' => "/sa/en/blog/{$postSlug}",
            'projects' => '/sa/en/projects',
            'project' => '/sa/en/projects/dressing-room',
            'campaign' => '/sa/en/campaigns/winter-offers',
        ]);

        // Measured 2026-11-11, warm, the session kept in memory as the tests keep it. Every page: the frame's 6 - the store 2,
        // Catalog's menu 2, Content's frame 2, each the cache's version and its copy, read around the page's own as the shared
        // props are lazy. Then the page's own: the home's cached Content 2 (its own remember reads the version
        // again) and the best sellers' cards 1 - a tile's photos 1 more once a category has one; a page 2 (the item, then its
        // files, products and photos in one); the blog 3 (its categories, its posts, the covers' addresses); a post 4 (its
        // 2, the files' addresses, the products' cards); the projects 3 (whether any is published, the list, the photos); a
        // project 3; a campaign 5 (the run, Pricing's three reads of the run's products, the cards). Over the target of 8:
        // recorded, not hidden (owner, 2026-10-19).
        expect($counts)->toBe(['home' => 9, 'page' => 8, 'blog' => 9, 'post' => 10, 'projects' => 9, 'project' => 9, 'campaign' => 11]);
    });
});

/** The shop's next requests seen as this kind of shopper, each starting afresh (as Catalog's ShopPagesTest). */
function contentShopViewer(ShopperKind $kind): void
{
    app()->forgetScopedInstances();
    app()->scoped(ShopperType::class, static fn (): ShopperType => new readonly class($kind) implements ShopperType
    {
        public function __construct(private ShopperKind $kind) {}

        public function current(): ShopperKind
        {
            return $this->kind;
        }
    });
}

/** The campaign job's tick, as the system. */
function contentShopTick(): void
{
    Fx::asSystem(fn (): int => app(AdvanceCampaignsHandler::class)->handle(new AdvanceCampaigns));
}

/** A product in a new category, chosen and on sale in the store, sold retail and wholesale (25.00, from 5 at 20.00). */
function contentShopWholesaleProduct(): string
{
    $p = Px::ready(['80 cm'], Px::category('Hinges'));
    $store = Fx::storeId('sa');
    Fx::asSystem(function () use ($p, $store): void {
        app(ChooseInStoreHandler::class)->handle(new ChooseInStore($store, $p['product'], true));
        app(SetSellingTermsHandler::class)->handle(new SetSellingTerms($store, $p['product'], [$p['variants'][0] => ['retail' => true, 'wholesale' => true]], wholesaleMinimum: 5));
    });
    Px::onSale('sa', $p['product']);
    Rx::set('sa', [$p['variants'][0] => '25.00']);
    Fx::asSystem(fn () => app(SetWholesalePricesHandler::class)->handle(new SetWholesalePrices($store, $p['variants'][0], [5 => '20.00'])));

    return $p['product'];
}

describe('who is looking (catalog.md amendments 14, 35, 46)', function () {
    it('gives an individual no company-only product, neither in the drawer\'s counts nor among a post\'s cards, and no wholesale price', function () {
        $companies = Px::category('Workshops');
        $forCompanies = Px::ready(['60 cm'], $companies);
        Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId('sa'), $forCompanies['product'], true)));
        Px::onSale('sa', $forCompanies['product']);
        Fx::asSystem(fn () => app(SetCategoryAudiencesHandler::class)->handle(new SetCategoryAudiences(Fx::storeId('sa'), $companies, false)));
        $wholesale = contentShopWholesaleProduct();
        $post = contentShopPost(products: [$forCompanies['product'], $wholesale]);
        $url = '/sa/en/blog/'.DB::table('content.blog_post_translations')->where('post_id', $post)->where('locale', 'en')->value('slug');
        $card = static fn (array $page, string $product): ?array => array_values(array_filter($page['products'], static fn (array $card): bool => $card['productId'] === $product))[0] ?? null;
        $counts = static fn (array $page): array => array_column($page['frame']['catalog']['menu'], 'products', 'id');

        contentShopViewer(ShopperKind::Guest);
        $guest = contentShopPage($url, 'Content/Storefront/Document');

        expect(array_column($guest['products'], 'productId'))->toBe([$forCompanies['product'], $wholesale])
            ->and($counts($guest)[$companies] ?? null)->toBe(1)
            ->and($card($guest, $wholesale)['price']['wholesaleFrom']['minor'] ?? null)->toBe(20_00);

        contentShopViewer(ShopperKind::Individual);
        $individual = contentShopPage($url, 'Content/Storefront/Document');

        expect(array_column($individual['products'], 'productId'))->toBe([$wholesale])
            ->and($counts($individual))->not->toHaveKey($companies)
            ->and($card($individual, $wholesale)['price'] ?? null)->toHaveKey('wholesaleFrom')
            ->and($card($individual, $wholesale)['price']['wholesaleFrom'] ?? null)->toBeNull();

        contentShopViewer(ShopperKind::Company);
        $company = contentShopPage($url, 'Content/Storefront/Document');

        expect(array_column($company['products'], 'productId'))->toBe([$forCompanies['product'], $wholesale])
            ->and($card($company, $wholesale)['price']['wholesaleFrom']['minor'] ?? null)->toBe(20_00);
    });
});

describe('the head (§1.14)', function () {
    it('takes the SEO defaults saved after the page was read: the frame\'s cached copy is replaced', function () {
        contentShopPublishedPage(['titleEn' => 'About Us']);

        expect(contentShopPage('/sa/en/pages/about-us', 'Content/Storefront/Document')['head']['title'])->toBe('About Us');

        $save = static function (string $site): void {
            app(SaveSeoDefaultsHandler::class)->handle(new SaveSeoDefaults(Fx::storeId('sa'), 'تاتش وود', $site, ':title | :site', ':title - :site', 'وصف', 'A description', null, null));
        };
        Cx::editor([ContentPermissions::SEO_MANAGE]);
        $save('TouchWood');
        $page = contentShopPage('/sa/en/pages/about-us', 'Content/Storefront/Document');

        expect($page['head']['title'])->toBe('About Us - TouchWood')
            ->and($page['head']['description'])->toBe('A description');

        $save('TouchWood KSA');

        expect(contentShopPage('/sa/en/pages/about-us', 'Content/Storefront/Document')['head']['title'])->toBe('About Us - TouchWood KSA');
    });
});

describe('H7 a campaign\'s address and its life (§4.5, §9.3f F2, F6)', function () {
    it('gives a second campaign of the same name the next free address in each language', function () {
        $p = contentShopProduct();
        ['run' => $first] = contentShopCampaign($p['product']);
        ['run' => $second] = contentShopCampaign($p['product']);
        $slugs = static fn (string $run): array => (array) DB::table('content.campaign_runs')->where('id', $run)->first(['slug_ar', 'slug_en']);

        expect($slugs($first))->toBe(['slug_ar' => 'عروض-الشتاء', 'slug_en' => 'winter-offers'])
            ->and($slugs($second))->toBe(['slug_ar' => 'عروض-الشتاء-2', 'slug_en' => 'winter-offers-2']);
    });

    it('keeps rule 15 for an Arabic name of Latin letters only: its Arabic address is the Arabic word for a campaign', function () {
        $p = contentShopProduct();
        ['run' => $run] = contentShopCampaign($p['product'], 'Black Friday', 'Black Friday');
        ['run' => $again] = contentShopCampaign($p['product'], 'Black Friday', 'Black Friday');

        expect((array) DB::table('content.campaign_runs')->where('id', $run)->first(['slug_ar', 'slug_en']))->toBe(['slug_ar' => 'حملة', 'slug_en' => 'black-friday'])
            ->and(DB::table('content.campaign_runs')->where('id', $again)->value('slug_ar'))->toBe('حملة-2');
    });

    it('answers 404 for a cancelled run, and 301 home for a run the job has not ended though its end has come', function () {
        $p = contentShopProduct();
        ['campaign' => $cancelled] = contentShopCampaign($p['product'], 'عروض ملغاة', 'Cancelled Offers');
        app(CancelCampaignHandler::class)->handle(new CancelCampaign($cancelled));

        expect(contentShopAnswer('/sa/en/campaigns/cancelled-offers'))->toBe('404 ');

        ['run' => $run] = contentShopCampaign($p['product']);
        Cx::clockOn(2);
        contentShopTick();
        expect(contentShopAnswer('/sa/en/campaigns/winter-offers'))->toStartWith('200');

        // Past its end, still LIVE: the job has not ticked.
        Cx::clockOn(60 * 24 * 6);

        expect(DB::table('content.campaign_runs')->where('id', $run)->value('state'))->toBe('LIVE')
            ->and(contentShopAnswer('/sa/en/campaigns/winter-offers'))->toBe('301 '.url('/sa/en'));
    });

    it('pages a large target through what the store lists: no empty page behind Show More, 24 cards a page', function () {
        $category = Px::category('Slides');

        // 100 products of the category this store never chose, made first, so they hold the lowest ids.
        foreach (range(1, 100) as $n) {
            Px::ready(['60 cm'], $category);
        }

        $sold = [];

        foreach (range(1, 25) as $n) {
            $p = Px::ready(['60 cm'], $category);
            Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId('sa'), $p['product'], true)));
            Px::onSale('sa', $p['product']);
            $sold[] = $p['product'];
        }

        sort($sold, SORT_STRING);
        contentShopCampaign($category, kind: 'CATEGORY');
        Cx::clockOn(2);
        contentShopTick();

        $first = contentShopPage('/sa/en/campaigns/winter-offers', 'Content/Storefront/Campaign');

        expect(array_column($first['products'], 'productId'))->toBe(array_slice($sold, 0, 24))
            ->and($first['next'])->toBe($sold[23]);

        $second = contentShopPage('/sa/en/campaigns/winter-offers?after='.$first['next'], 'Content/Storefront/Campaign');

        expect(array_column($second['products'], 'productId'))->toBe([$sold[24]])
            ->and($second['next'])->toBeNull();

        // The query count on such a page: the frame's 6, the run 1, Pricing's reach 4 (the run's prices, targets and
        // products left out, its category's products), the page of cards 1. Recorded (owner, 2026-10-19).
        expect(contentShopQueries('/sa/en/campaigns/winter-offers'))->toBe(12);
    });
});
