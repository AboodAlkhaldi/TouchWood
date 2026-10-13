<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Catalog\Application\Command\DeactivateBrand\DeactivateBrand;
use Modules\Catalog\Application\Command\DeactivateBrand\DeactivateBrandHandler;
use Modules\Catalog\Application\Command\EditCategory\EditCategory;
use Modules\Catalog\Application\Command\EditCategory\EditCategoryHandler;
use Modules\Catalog\Application\Command\EditProductDetails\EditProductDetails;
use Modules\Catalog\Application\Command\EditProductDetails\EditProductDetailsHandler;
use Modules\Catalog\Application\Command\SetCategoryAudiences\SetCategoryAudiences;
use Modules\Catalog\Application\Command\SetCategoryAudiences\SetCategoryAudiencesHandler;
use Modules\Catalog\Application\Command\SetSellingTerms\SetSellingTerms;
use Modules\Catalog\Application\Command\SetSellingTerms\SetSellingTermsHandler;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Modules\Catalog\Infrastructure\Queue\PruneShopPagesJob;
use Modules\Catalog\Public\Contracts\ListingFacts;
use Modules\Platform\Public\Contracts\ShopperType;
use Modules\Platform\Public\Enums\ShopperKind;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;

use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\seed;

/*
| The shop's pages (catalog.md §4.5; P14–P22): the addresses, 301 and 404 in the shop's frame, who is
| looking, the filters and the sort, the search and its suggestions, "Last pieces", the menu and the
| search box in every page's frame, the never-stale cache, and each page's queries, warm.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

/**
 * Whoever the shop takes the next requests' viewer to be, as Access would answer it — each request
 * starting as a production one does, with nothing scoped from the one before.
 */
function shopPagesViewer(ShopperKind $kind): void
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

/**
 * A ready product in this category, chosen in these stores.
 *
 * @param  list<string>  $sizes
 * @param  list<string>  $stores
 * @return array{product: string, variants: list<string>, width: string}
 */
function shopPagesProduct(string $categoryId, array $sizes = ['60 cm'], array $stores = ['sa']): array
{
    $p = Px::ready($sizes, $categoryId);

    foreach ($stores as $store) {
        Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId($store), $p['product'], true)));
    }

    return $p;
}

function shopPagesSlug(string $kind, string $id, string $locale = 'en'): string
{
    return (string) DB::table("catalog.{$kind}_slugs")->where("{$kind}_id", $id)->where('locale', $locale)->where('is_current', true)->value('slug');
}

function shopPagesBrandOf(string $productId): string
{
    return (string) DB::table('catalog.products')->where('id', $productId)->value('brand_id');
}

/** How many queries a page asks, warm: once to fill the caches, then counted as a new request. */
function shopPagesQueries(string $url): int
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

describe('the addresses', function () {
    it('shows a category\'s page with its path, its cards, its filters and both its addresses', function () {
        $top = Px::category('Kitchens');
        $category = Px::category('Drawers', $top);
        $p = shopPagesProduct($category, ['60 cm', '80 cm']);
        $slug = shopPagesSlug('category', $category);

        get("/sa/en/categories/{$slug}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Catalog/Storefront/Category')
            ->where('slug', $slug)
            ->where('path.0.slug', shopPagesSlug('category', $top))
            ->where('products.0.productId', $p['product'])
            ->where('products.0.lastPieces', false)
            ->where('next', null)
            ->where('filters.sort', 'best-selling')
            ->where('filters.brands.0.id', shopPagesBrandOf($p['product']))
            ->where('filters.brands.0.count', 1)
            ->has('filters.attributes.0.choices', 2)
            ->where('filters.sides.0.id', 'retail')
            ->where('address.canonical', url("/sa/en/categories/{$slug}"))
            ->where('address.alternate', url('/sa/ar/categories/'.rawurlencode(shopPagesSlug('category', $category, 'ar'))))
            ->where('address.noindex', false)
            // The menu and the search box in the frame: the top category and, under it, this one.
            ->where('frame.catalog.menu.0.id', $top)
            ->where('frame.catalog.menu.0.children.0.id', $category)
            ->has('frame.catalog.words.see_all')
        );
    });

    it('answers an old slug with 301 to the current one, the shopper\'s choices kept', function () {
        $category = Px::category('Kitchens');
        shopPagesProduct($category);
        $old = shopPagesSlug('category', $category);
        Fx::asSystem(fn () => app(EditCategoryHandler::class)->handle(new EditCategory($category, 'مطابخ', 'Kitchen fittings', slugEn: 'kitchen-fittings')));

        get("/sa/en/categories/{$old}?sort=newest")->assertStatus(301)->assertRedirect(url('/sa/en/categories/kitchen-fittings?sort=newest'));
    });

    it('answers 404 in the shop\'s frame for what is not a page here', function (Closure $url) {
        $category = Px::category('Kitchens');
        $p = shopPagesProduct($category);
        $empty = Px::category('Empty');
        $draft = Px::product('Draft');

        get($url($category, $p['product'], $empty, $draft))->assertNotFound()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Catalog/Storefront/NotFound')
            ->has('frame.catalog.menu')
        );
    })->with([
        'a slug that never existed' => [fn (): string => '/sa/en/products/no-such-product'],
        'a draft' => [fn ($c, $p, $e, string $draft): string => '/sa/en/products/'.shopPagesSlug('product', $draft)],
        'a category listing nothing here' => [fn ($c, $p, string $empty): string => '/sa/en/categories/'.shopPagesSlug('category', $empty)],
        'a category in a store that lists nothing of it' => [fn (string $category): string => '/eg/en/categories/'.shopPagesSlug('category', $category)],
        'a filter that is not one' => [fn (string $category): string => '/sa/en/categories/'.shopPagesSlug('category', $category).'?sort=cheapest'],
        'a page cursor made up' => [fn (string $category): string => '/sa/en/categories/'.shopPagesSlug('category', $category).'?after=drawer'],
    ]);

    it('answers 404 for an inactive brand, and an active one always has its page', function () {
        $p = shopPagesProduct(Px::category('Kitchens'));
        $brand = shopPagesBrandOf($p['product']);
        $slug = shopPagesSlug('brand', $brand);

        get("/sa/en/brands/{$slug}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Catalog/Storefront/Brand')
            ->where('products.0.productId', $p['product'])
            ->where('address.canonical', url("/sa/en/brands/{$slug}"))
        );

        Fx::asSystem(fn () => app(DeactivateBrandHandler::class)->handle(new DeactivateBrand($brand, 'HIDE')));

        get("/sa/en/brands/{$slug}")->assertNotFound();
    });

    it('carries the menu and the search box on the account pages too', function () {
        $category = Px::category('Kitchens');
        shopPagesProduct($category);

        get('/sa/en/sign-in')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('frame.catalog.menu.0.id', $category));
        // The country page belongs to no store, and has no frame of a store's.
        get('/')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('frame', []));
    });
});

describe('a product\'s page', function () {
    it('shows the choices, the chosen variant\'s details, how it is sold and its warranty; no code, no price', function () {
        $p = shopPagesProduct(Px::category('Kitchens'), ['60 cm', '80 cm']);
        $slug = shopPagesSlug('product', $p['product']);

        $response = get("/sa/en/products/{$slug}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Catalog/Storefront/Product')
            ->where('available', true)
            ->where('choices.0.id', $p['width'])
            ->has('choices.0.values', 2)
            ->where('choices.0.values.0.onSale', true)
            ->where('variants.0.id', $p['variants'][0])
            ->where('variants.0.values.'.$p['width'], (string) DB::table('catalog.variant_values')->where('variant_id', $p['variants'][0])->value('value_id'))
            ->where('selling.retailMinimum', 1)
            ->where('address.noindex', false)
            ->has('photos', 1)
        );

        $codes = DB::table('catalog.variants')->where('product_id', $p['product'])->pluck('code')->all();

        foreach ($codes as $code) {
            expect($response->getContent())->not->toContain((string) $code);
        }

        expect($response->getContent())->not->toContain('price');
    });

    it('shows a product this viewer\'s account type does not see as not available now, kept out of search engines (P18)', function () {
        $category = Px::category('Kitchens');
        $p = shopPagesProduct($category);
        Fx::asSystem(fn () => app(SetCategoryAudiencesHandler::class)->handle(new SetCategoryAudiences(Fx::storeId('sa'), $category, false)));
        shopPagesViewer(ShopperKind::Individual);

        get('/sa/en/products/'.shopPagesSlug('product', $p['product']))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('available', false)
            ->where('choices', [])
            ->where('variants', [])
            ->where('address.noindex', true)
            ->has('photos', 1)
        );
    });

    it('is never stale: a change to the product shows at once', function () {
        $category = Px::category('Kitchens');
        $p = shopPagesProduct($category);
        $url = '/sa/en/products/'.shopPagesSlug('product', $p['product']);
        get($url)->assertOk();

        Fx::asSystem(function () use ($p, $category): void {
            $product = app(ProductRepository::class)->find($p['product']) ?? throw new LogicException('No such product.');
            $text = ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Drawer']]]]];
            app(EditProductDetailsHandler::class)->handle(new EditProductDetails($p['product'], $product->name()->ar, 'Soft drawer', $product->brandId(), slugEn: shopPagesSlug('product', $p['product']), descriptionAr: $text, descriptionEn: $text, categoryId: $category));
        });

        app()->forgetScopedInstances();
        get($url)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('name', 'Soft drawer'));
    });

    it('says "Last pieces" on the ending-soon option only, and on its card, for the side that can buy it', function () {
        $category = Px::category('Kitchens');
        $p = shopPagesProduct($category, ['60 cm', '80 cm']);
        [$sixty, $eighty] = $p['variants'];
        // 80 cm sold wholesale only: an individual never sees it, nor its last pieces.
        Fx::asSystem(fn () => app(SetSellingTermsHandler::class)->handle(new SetSellingTerms(Fx::storeId('sa'), $p['product'], [$sixty => ['retail' => true, 'wholesale' => false], $eighty => ['retail' => false, 'wholesale' => true]], wholesaleMinimum: 10)));
        // Inventory tells Catalog a size with no stock cannot be ordered (stage 5): both have stock here.
        app(ListingFacts::class)->orderable(StoreId::fromString(Fx::storeId('sa')), [$sixty, $eighty], true);
        app(ListingFacts::class)->endingSoon(StoreId::fromString(Fx::storeId('sa')), [$eighty], true);
        $product = '/sa/en/products/'.shopPagesSlug('product', $p['product']);
        $categoryUrl = '/sa/en/categories/'.shopPagesSlug('category', $category);

        get($product)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('variants.0.lastPieces', false)
            ->where('variants.1.lastPieces', true)
            ->where('variants.1.sellsWholesale', true)
            ->where('selling.wholesaleMinimum', 10)
        );
        get($categoryUrl)->assertInertia(fn (AssertableInertia $page) => $page->where('products.0.lastPieces', true));

        shopPagesViewer(ShopperKind::Individual);

        get($product)->assertInertia(fn (AssertableInertia $page) => $page->has('variants', 1)->where('variants.0.lastPieces', false)->where('selling.wholesaleMinimum', null));
        get($categoryUrl)->assertInertia(fn (AssertableInertia $page) => $page->where('products.0.lastPieces', false)->where('filters.sides', []));

        // Not orderable now: it lights no side (amendment 16(h)).
        app(ListingFacts::class)->orderable(StoreId::fromString(Fx::storeId('sa')), [$eighty], false);
        shopPagesViewer(ShopperKind::Company);
        get($categoryUrl)->assertInertia(fn (AssertableInertia $page) => $page->where('products.0.lastPieces', false));
    });
});

describe('the filters and the sort', function () {
    it('narrows by brand, by value and by how it is sold, each choice counted with the others kept', function () {
        $category = Px::category('Kitchens');
        $a = shopPagesProduct($category, ['60 cm']);
        $b = shopPagesProduct($category, ['80 cm']);
        Fx::asSystem(fn () => app(SetSellingTermsHandler::class)->handle(new SetSellingTerms(Fx::storeId('sa'), $b['product'], [$b['variants'][0] => ['retail' => false, 'wholesale' => true]], wholesaleMinimum: 5)));
        $url = '/sa/en/categories/'.shopPagesSlug('category', $category);
        $brandA = shopPagesBrandOf($a['product']);

        get("{$url}?brands={$brandA}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('products', 1)
            ->where('products.0.productId', $a['product'])
            // Its own kind left out: the other brand still says what it would show.
            ->where('filters.brands.0.count', 1)
            ->where('filters.brands.1.count', 1)
            ->where('filters.sides.1.id', 'wholesale')
            ->where('filters.sides.1.count', 0)
        );

        get("{$url}?side=wholesale")->assertInertia(fn (AssertableInertia $page) => $page->has('products', 1)->where('products.0.productId', $b['product']));

        $sixty = (string) DB::table('catalog.variant_values')->where('variant_id', $a['variants'][0])->value('value_id');
        get("{$url}?values={$sixty}")->assertInertia(fn (AssertableInertia $page) => $page->has('products', 1)->where('products.0.productId', $a['product']));
    });

    it('sorts best-selling first by default - newest until Sales ranks - and newest when asked', function () {
        $category = Px::category('Kitchens');
        $first = shopPagesProduct($category);
        $second = shopPagesProduct($category);
        DB::table('catalog.listing')->where('product_id', $first['product'])->update(['sales_rank' => 1]);
        $url = '/sa/en/categories/'.shopPagesSlug('category', $category);

        get($url)->assertInertia(fn (AssertableInertia $page) => $page->where('products.0.productId', $first['product']));
        get("{$url}?sort=newest")->assertInertia(fn (AssertableInertia $page) => $page->where('products.0.productId', $second['product'])->where('filters.sort', 'newest'));
    });
});

describe('the search', function () {
    it('shows the results, logs the search once, and suggests while typing without logging', function () {
        $p = shopPagesProduct(Px::category('Kitchens'));

        getJson('/sa/en/search/suggest?q=Dra')->assertOk()->assertJsonPath('products.0.productId', $p['product']);
        expect(DB::table('catalog.search_log')->count())->toBe(0);

        get('/sa/en/search?q=Drawer')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Catalog/Storefront/Search')
            ->where('total', 1)
            ->where('products.0.productId', $p['product'])
            ->where('next', null)
            ->where('address.noindex', true)
        );
        expect(DB::table('catalog.search_log')->count())->toBe(1);
    });

    it('answers nothing found with the categories to go to', function () {
        shopPagesProduct(Px::category('Kitchens'));

        get('/sa/en/search?q=zzzzqqq')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('total', 0)->has('frame.catalog.menu', 1));
    });
});

describe('the review\'s fixes', function () {
    it('never offers an individual a size sold wholesale only, not even disabled (§1.13, 14(e))', function () {
        $p = shopPagesProduct(Px::category('Kitchens'), ['60 cm', '80 cm']);
        [$sixty, $eighty] = $p['variants'];
        Fx::asSystem(fn () => app(SetSellingTermsHandler::class)->handle(new SetSellingTerms(Fx::storeId('sa'), $p['product'], [$sixty => ['retail' => true, 'wholesale' => false], $eighty => ['retail' => false, 'wholesale' => true]], wholesaleMinimum: 10)));
        $url = '/sa/en/products/'.shopPagesSlug('product', $p['product']);

        get($url)->assertInertia(fn (AssertableInertia $page) => $page->has('choices.0.values', 2));

        shopPagesViewer(ShopperKind::Individual);
        get($url)->assertInertia(fn (AssertableInertia $page) => $page->has('choices.0.values', 1)->where('choices.0.values.0.onSale', true));
    });

    it('counts a choice the page no longer offers as not made, in the cards as in the chips', function () {
        $category = Px::category('Kitchens');
        $a = shopPagesProduct($category);
        $b = shopPagesProduct($category);
        $elsewhere = shopPagesBrandOf(shopPagesProduct(Px::category('Doors'))['product']);
        $url = '/sa/en/categories/'.shopPagesSlug('category', $category);

        get("{$url}?brands={$elsewhere}&side=wholesale")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->has('products', 2)
            ->where('filters.brands.0.chosen', false)
            ->where('filters.brands.0.count', 1)
            ->has('filters.sides', 1)
            ->where('filters.sides.0.id', 'retail')
            ->where('filters.sides.0.chosen', false)
        );
        expect([$a['product'], $b['product']])->not->toBeEmpty();
    });

    it('keeps the query on a 301, but never lets it set the store or the language', function () {
        $category = Px::category('Kitchens');
        shopPagesProduct($category);
        $old = shopPagesSlug('category', $category);
        Fx::asSystem(fn () => app(EditCategoryHandler::class)->handle(new EditCategory($category, 'مطابخ', 'Kitchen fittings', slugEn: 'kitchen-fittings')));

        get("/sa/en/categories/{$old}?sort=newest&locale=ar&store=eg")->assertStatus(301)->assertRedirect(url('/sa/en/categories/kitchen-fittings?sort=newest'));
    });

    it('draws a product\'s path from this viewer\'s menu, never a category they cannot open', function () {
        $top = Px::category('Kitchens');
        $category = Px::category('Drawers', $top);
        $p = shopPagesProduct($category);
        $url = '/sa/en/products/'.shopPagesSlug('product', $p['product']);

        get($url)->assertInertia(fn (AssertableInertia $page) => $page->where('path.0.slug', shopPagesSlug('category', $top))->where('path.1.slug', shopPagesSlug('category', $category)));

        Fx::asSystem(fn () => app(SetCategoryAudiencesHandler::class)->handle(new SetCategoryAudiences(Fx::storeId('sa'), $top, false)));
        shopPagesViewer(ShopperKind::Individual);

        get($url)->assertInertia(fn (AssertableInertia $page) => $page->where('available', false)->where('path', []));
    });

    it('removes what old versions kept once out of date, by the hour, and keeps the version', function () {
        $category = Px::category('Kitchens');
        $p = shopPagesProduct($category);
        get('/sa/en/products/'.shopPagesSlug('product', $p['product']))->assertOk();
        $prefix = (string) config('cache.prefix');
        $kept = static fn (): int => DB::table('cache')->where('key', 'like', $prefix.'catalog:shop:%')->count();
        $before = $kept();

        DB::table('cache')->where('key', 'like', $prefix.'catalog:shop:%')->update(['expiration' => 1]);
        PruneShopPagesJob::dispatch();

        expect($before)->toBeGreaterThan(1)
            ->and($kept())->toBe(1)
            ->and(DB::table('cache')->where('key', $prefix.'catalog:shop:version')->exists())->toBeTrue()
            ->and(array_values(array_map(static fn ($event): string => $event->expression, array_filter(app(Schedule::class)->events(), static fn ($event): bool => $event->description === PruneShopPagesJob::class))))->toBe(['0 * * * *']);
    });
});

describe('the budget (P21)', function () {
    it('keeps every shop page within 8 queries warm, for a guest', function () {
        $category = Px::category('Kitchens');
        $p = shopPagesProduct($category, ['60 cm', '80 cm']);
        shopPagesProduct($category, ['60 cm']);
        $pages = [
            'category' => '/sa/en/categories/'.shopPagesSlug('category', $category),
            'brand' => '/sa/en/brands/'.shopPagesSlug('brand', shopPagesBrandOf($p['product'])),
            'product' => '/sa/en/products/'.shopPagesSlug('product', $p['product']),
            'search' => '/sa/en/search?q=Drawer',
            'suggest' => '/sa/en/search/suggest?q=Drawer',
            'sign-in' => '/sa/en/sign-in',
        ];

        $counts = array_map(shopPagesQueries(...), $pages);

        // Measured 2026-10-12, the session kept in memory as the tests keep it: the frame 2 (the
        // store, from the cache), the menu 2 (the cache's version and the menu), then the page's own -
        // a category's head with its filters, and its cards, 2; a brand's head and cards 2 (and its
        // logo's addresses, when it has one, 1); a product's owner 1 and its page 1 (the cache's version
        // read once a request, shared with the menu); a search's word pairs, results and log 3. The
        // suggestions, JSON with no frame: the store 2, the word pairs and the search 2. An account page
        // carries the menu: its 4 (the store's 2, the settings' 2, all from the cache) and the menu's 2.
        expect($counts)->toBe(['category' => 6, 'brand' => 6, 'product' => 6, 'search' => 7, 'suggest' => 4, 'sign-in' => 6]);
    });

    it('records each page as production serves it - the session in the database - for a guest, an individual and a company', function () {
        config(['session.driver' => 'database', 'session.lottery' => [0, 100]]);
        FakeBreachList::install();
        RecordingSecurityMessages::install();
        $category = Px::category('Kitchens');
        $p = shopPagesProduct($category, ['60 cm', '80 cm']);
        $pages = [
            'category' => '/sa/en/categories/'.shopPagesSlug('category', $category),
            'brand' => '/sa/en/brands/'.shopPagesSlug('brand', shopPagesBrandOf($p['product'])),
            'product' => '/sa/en/products/'.shopPagesSlug('product', $p['product']),
            'search' => '/sa/en/search?q=Drawer',
        ];
        $measure = static function (AdminBrowser $browser) use ($pages): array {
            $counts = [];

            foreach ($pages as $name => $url) {
                $browser->get($url)->assertOk();
                DB::flushQueryLog();
                DB::enableQueryLog();
                $browser->get($url)->assertOk();
                DB::disableQueryLog();
                $counts[$name] = count(DB::getQueryLog());
            }

            return $counts;
        };
        $signedIn = static function (string $email, string $type): AdminBrowser {
            Fx::customer($email, 'sa', $type);
            $browser = new AdminBrowser('10.13.1.'.random_int(20, 250));
            $browser->post('/sa/en/account/sign-in', ['email' => $email, 'password' => Fx::CUSTOMER_PASSWORD])->assertRedirect('/sa/en');

            return $browser;
        };

        // Measured 2026-10-12, each request from a fresh process (AdminBrowser): the session's row read
        // and written (2) on top of the counts above; a signed-in customer adds Access's own reads of
        // who they are (5). Over 8 - a guest's search, and every page for someone signed in - is
        // brought to the owner with these counts (P21), never raised here quietly.
        expect([
            'guest' => $measure(new AdminBrowser('10.13.1.10')),
            'individual' => $measure($signedIn('sara@example.test', 'individual')),
            'company' => $measure($signedIn('works@example.test', 'company')),
        ])->toBe([
            'guest' => ['category' => 8, 'brand' => 8, 'product' => 8, 'search' => 9],
            'individual' => ['category' => 13, 'brand' => 13, 'product' => 13, 'search' => 14],
            'company' => ['category' => 13, 'brand' => 13, 'product' => 13, 'search' => 14],
        ]);
    });
});
