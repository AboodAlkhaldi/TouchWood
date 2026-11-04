<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Modules\Catalog\Application\Command\ArchiveProduct\ArchiveProduct;
use Modules\Catalog\Application\Command\ArchiveProduct\ArchiveProductHandler;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Catalog\Application\Command\CreateProduct\CreateProduct;
use Modules\Catalog\Application\Command\CreateProduct\CreateProductHandler;
use Modules\Catalog\Application\Command\DeactivateBrand\DeactivateBrand;
use Modules\Catalog\Application\Command\DeactivateBrand\DeactivateBrandHandler;
use Modules\Catalog\Application\Command\DeactivateCategory\DeactivateCategory;
use Modules\Catalog\Application\Command\DeactivateCategory\DeactivateCategoryHandler;
use Modules\Catalog\Application\Command\EditBrand\EditBrand;
use Modules\Catalog\Application\Command\EditBrand\EditBrandHandler;
use Modules\Catalog\Application\Command\EditCategory\EditCategory;
use Modules\Catalog\Application\Command\EditCategory\EditCategoryHandler;
use Modules\Catalog\Application\Command\EditProductDetails\EditProductDetails;
use Modules\Catalog\Application\Command\EditProductDetails\EditProductDetailsHandler;
use Modules\Catalog\Application\Command\MarkNotAvailableNow\MarkNotAvailableNow;
use Modules\Catalog\Application\Command\MarkNotAvailableNow\MarkNotAvailableNowHandler;
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
use Symfony\Component\HttpFoundation\Cookie;
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
        Px::onSale($store, $p['product']);
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
            // The shop's one not-found sentence, as its error page says it (owner, 2026-10-20).
            ->where('translations', fn (Collection $words): bool => $words->get('errors.shop.sentence.404') === 'This page isn\'t available. Check the address, or go back to the store.'
                && ! $words->has('catalog::shop.not_found.text'))
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
    it('shows the choices, the chosen variant\'s details, how it is sold and its warranty; no code, and no price before one is set', function () {
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
            // Its price, no sale (amendment 35; ShopPricesTest shows the rest; unpriced, it is not on sale, amendment 39).
            ->where('variants.0.price.minor', 10_00)
            ->where('variants.0.priceBefore', null)
        );

        $codes = DB::table('catalog.variants')->where('product_id', $p['product'])->pluck('code')->all();

        foreach ($codes as $code) {
            expect($response->getContent())->not->toContain((string) $code);
        }
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
        // A product's page, one more since 2026-10-19 (catalog.md amendment 26(b)): Feedback's sections -
        // its rating, reviews, questions and the wishlist button - in one statement (more only once
        // there are reviews or answers: their authors' names and the sizes bought, read in bulk).
        // A product's page, six more since 2026-10-27 (amendment 36, sales.md §4.2 B15): Sales's Add to Cart -
        // Catalog's sizes in the store 1, Inventory's stock 4 (the wiring, the counts, ending soon, the
        // low-stock setting) and, for a guest, whether companies may buy the product 1; the owner chose the
        // exact check over the cheaper one regardless of the count. The limits are a target, not a cap
        // (owner, 2026-10-19). A product in stock, as a listed one now always is (amendment 39), one more than
        // the 13 measured with no stock rows: Inventory's ending-soon switches, read only once a size has a row.
        expect($counts)->toBe(['category' => 6, 'brand' => 6, 'product' => 14, 'search' => 7, 'suggest' => 4, 'sign-in' => 6]);
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
            // Signed in, one more since 2026-10-19 (access.md amendment 72(d), loyalty.md §4.1 L7): the
            // account menu asks whether to offer the Points tab, and while a store's programme is off
            // (as every store starts) that reads whether the customer has points there. Brought to the
            // owner with the Loyalty screens' PR. A product's page, one more since 2026-10-19 for
            // Feedback's sections (amendment 26(b)) - the limits a target, not a cap (owner, 2026-10-19).
            // Signed in, a page of cards one more since 2026-10-24 (amendment 32, feedback.md §2.4 B5): which
            // of its cards the customer saved here - none for a guest, none for a page with no cards (this
            // product page has no related products). Signed in, a product's page one more since 2026-10-26
            // (owner's Q14): whether the product was delivered to the customer, for Write a Review - asked
            // only while they have not reviewed it. A product's page, since 2026-10-27, Sales's Add to Cart
            // (amendment 36): six more for a guest, four for someone signed in, whose customer and store
            // reads it shares with the page (the cart's lines one of them). Signed in, every page one more
            // since 2026-10-27 (sales.md §4.1 S3, §4.2 B13): the header's count of the pieces in the cart -
            // none for a guest, whose count is read from the cart's cookie only when there is one. Signed in,
            // every page one more since 2026-11-02 (ops.md §4.15 O2, Q7): the bell's number; none for a guest. A
            // product in stock, as a listed one now always is (amendment 39), one more: Inventory's ending-soon switches.
        ])->toBe([
            'guest' => ['category' => 8, 'brand' => 8, 'product' => 16, 'search' => 9],
            'individual' => ['category' => 17, 'brand' => 17, 'product' => 23, 'search' => 18],
            'company' => ['category' => 17, 'brand' => 17, 'product' => 23, 'search' => 18],
        ]);
    });
});

/**
 * Give an item these slugs through its own form, as staff would; the one left out stays. The slug it
 * replaces stays held, as an old one (§1.1).
 */
function shopPagesRename(string $kind, string $id, ?string $ar = null, ?string $en = null): void
{
    $ar ??= shopPagesSlug($kind, $id, 'ar');
    $en ??= shopPagesSlug($kind, $id);
    $text = ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Text']]]]];

    Fx::asSystem(function () use ($kind, $id, $ar, $en, $text): void {
        if ($kind === 'product') {
            $product = app(ProductRepository::class)->find($id) ?? throw new LogicException('No product.');
            app(EditProductDetailsHandler::class)->handle(new EditProductDetails($id, $product->name()->ar, $product->name()->en, $product->brandId(), slugAr: $ar, slugEn: $en, descriptionAr: $text, descriptionEn: $text, categoryId: $product->categoryId()));

            return;
        }

        $table = $kind === 'category' ? 'catalog.categories' : 'catalog.brands';
        $row = DB::table($table)->where('id', $id)->first() ?? throw new LogicException('No row.');

        if ($kind === 'category') {
            app(EditCategoryHandler::class)->handle(new EditCategory($id, (string) $row->name_ar, (string) $row->name_en, slugAr: $ar, slugEn: $en));

            return;
        }

        app(EditBrandHandler::class)->handle(new EditBrand($id, (string) $row->name_ar, (string) $row->name_en, (string) $row->agency_type, (bool) $row->show_in_default_listings, slugAr: $ar, slugEn: $en));
    });
}

/**
 * All a not-found answer tells a visitor, but what changes with every request whatever is asked: the
 * date, the correlation id, the cookies' values and the CSRF token.
 *
 * @return array{status: int, component: mixed, props: mixed, headers: array<string, mixed>, cookies: list<string>}
 */
function shopPagesAnswer(string $url): array
{
    app()->forgetScopedInstances();
    $response = get($url);
    /** @var array{component: mixed, props: array<string, mixed>} $page */
    $page = $response->viewData('page');
    unset($page['props']['csrfToken']);
    $headers = $response->headers->all();
    unset($headers['date'], $headers['set-cookie'], $headers['x-correlation-id']);
    $cookies = array_map(static fn (Cookie $cookie): string => $cookie->getName(), $response->headers->getCookies());
    sort($cookies);

    return ['status' => $response->getStatusCode(), 'component' => $page['component'], 'props' => $page['props'], 'headers' => $headers, 'cookies' => $cookies];
}

/** A product, a category and a brand, each with a page in Saudi Arabia. */
dataset('the three kinds', [
    'a product' => ['products', 'product', fn (): string => shopPagesProduct(Px::category('Kitchens'))['product']],
    'a category' => ['categories', 'category', function (): string {
        $category = Px::category('Kitchens');
        shopPagesProduct($category);

        return $category;
    }],
    'a brand' => ['brands', 'brand', fn (): string => shopPagesBrandOf(shopPagesProduct(Px::category('Kitchens'))['product'])],
]);

describe('the other language\'s slug (amendment 38: a notice carries one link)', function () {
    it('answers 301 to the slug in the page\'s language, both ways', function (string $path, string $kind, Closure $make) {
        $id = $make();
        $en = shopPagesSlug($kind, $id);
        $ar = shopPagesSlug($kind, $id, 'ar');

        get("/sa/ar/{$path}/{$en}")->assertStatus(301)->assertRedirect(url("/sa/ar/{$path}/".rawurlencode($ar)));
        get("/sa/en/{$path}/".rawurlencode($ar))->assertStatus(301)->assertRedirect(url("/sa/en/{$path}/{$en}"));
        // In capitals too, as an English slug is read under its own language.
        get("/sa/ar/{$path}/".strtoupper($en))->assertStatus(301)->assertRedirect(url("/sa/ar/{$path}/".rawurlencode($ar)));
    })->with('the three kinds');

    it('answers an old slug of the other language with one hop to the current one', function (string $path, string $kind, Closure $make) {
        $id = $make();
        $oldEn = shopPagesSlug($kind, $id);
        $oldAr = shopPagesSlug($kind, $id, 'ar');
        shopPagesRename($kind, $id, 'اسم-جديد', 'new-name');

        $toArabic = get("/sa/ar/{$path}/{$oldEn}")->assertStatus(301)->assertRedirect(url("/sa/ar/{$path}/".rawurlencode('اسم-جديد')));
        $toEnglish = get("/sa/en/{$path}/".rawurlencode($oldAr))->assertStatus(301)->assertRedirect(url("/sa/en/{$path}/new-name"));

        // The address it sends to is the page: no second redirect.
        get((string) $toArabic->headers->get('Location'))->assertOk();
        get((string) $toEnglish->headers->get('Location'))->assertOk();
    })->with('the three kinds');

    it('keeps the query, and never lets it set the store or the language', function (string $path, string $kind, Closure $make) {
        $id = $make();
        $ar = rawurlencode(shopPagesSlug($kind, $id, 'ar'));
        $en = shopPagesSlug($kind, $id);

        get("/sa/ar/{$path}/{$en}?utm_source=notice&locale=en&store=eg")->assertStatus(301)->assertRedirect(url("/sa/ar/{$path}/{$ar}?utm_source=notice"));
    })->with('the three kinds');

    it('keeps a shopper\'s filters on a category', function () {
        $category = Px::category('Kitchens');
        shopPagesProduct($category);
        $ar = rawurlencode(shopPagesSlug('category', $category, 'ar'));

        get('/sa/ar/categories/'.shopPagesSlug('category', $category).'?sort=newest')->assertStatus(301)->assertRedirect(url("/sa/ar/categories/{$ar}?sort=newest"));
    });

    it('still answers 404 for a slug no one ever held', function (string $url) {
        get($url)->assertNotFound()->assertInertia(fn (AssertableInertia $page) => $page->component('Catalog/Storefront/NotFound'));
    })->with([
        'a product, under Arabic' => ['/sa/ar/products/no-such-product'],
        'a product, under English' => ['/sa/en/products/%D9%84%D8%A7-%D9%8A%D9%88%D8%AC%D8%AF'],
        'a category, under Arabic' => ['/sa/ar/categories/no-such-category'],
        'a category, under English' => ['/sa/en/categories/%D9%84%D8%A7-%D9%8A%D9%88%D8%AC%D8%AF'],
        'a brand, under Arabic' => ['/sa/ar/brands/no-such-brand'],
        'a brand, under English' => ['/sa/en/brands/%D9%84%D8%A7-%D9%8A%D9%88%D8%AC%D8%AF'],
    ]);

    it('forwards exactly when the own address opens: a product not on sale', function (Closure $off) {
        $category = Px::category('Kitchens');
        $p = shopPagesProduct($category);
        $en = shopPagesSlug('product', $p['product']);
        $ar = rawurlencode(shopPagesSlug('product', $p['product'], 'ar'));
        $off($p, $category);

        get("/sa/en/products/{$en}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('available', false));
        get("/sa/ar/products/{$ar}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('available', false));
        $toArabic = get("/sa/ar/products/{$en}")->assertStatus(301)->assertRedirect(url("/sa/ar/products/{$ar}"));
        get("/sa/en/products/{$ar}")->assertStatus(301)->assertRedirect(url("/sa/en/products/{$en}"));
        // To the same page its own slug shows, in one hop.
        get((string) $toArabic->headers->get('Location'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Catalog/Storefront/Product')
            ->where('available', false));
    })->with([
        '"Not available now" here' => [function (array $p): void {
            Fx::asSystem(fn () => app(MarkNotAvailableNowHandler::class)->handle(new MarkNotAvailableNow(Fx::storeId('sa'), $p['product'])));
        }],
        'not chosen in this store' => [function (array $p): void {
            Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId('sa'), $p['product'], false)));
        }],
        'archived after being ready' => [function (array $p): void {
            Fx::asSystem(fn () => app(ArchiveProductHandler::class)->handle(new ArchiveProduct($p['product'])));
        }],
        'hidden with its category' => [function (array $p, string $category): void {
            Fx::asSystem(fn () => app(DeactivateCategoryHandler::class)->handle(new DeactivateCategory($category, 'HIDE')));
        }],
    ]);

    it('forwards a sold-out product to the page its own slug shows', function () {
        $p = shopPagesProduct(Px::category('Kitchens'), ['60 cm', '80 cm']);
        $en = shopPagesSlug('product', $p['product']);
        $ar = rawurlencode(shopPagesSlug('product', $p['product'], 'ar'));
        app(ListingFacts::class)->orderable(StoreId::fromString(Fx::storeId('sa')), $p['variants'], false);
        $own = fn (AssertableInertia $page) => $page->component('Catalog/Storefront/Product')->where('productId', $p['product']);

        get("/sa/en/products/{$en}")->assertOk()->assertInertia($own);
        get("/sa/ar/products/{$ar}")->assertOk()->assertInertia($own);
        $toArabic = get("/sa/ar/products/{$en}")->assertStatus(301)->assertRedirect(url("/sa/ar/products/{$ar}"));
        $toEnglish = get("/sa/en/products/{$ar}")->assertStatus(301)->assertRedirect(url("/sa/en/products/{$en}"));
        get((string) $toArabic->headers->get('Location'))->assertOk()->assertInertia($own);
        get((string) $toEnglish->headers->get('Location'))->assertOk()->assertInertia($own);
    });

    it('forwards an old slug of a product not on sale in one hop', function () {
        $category = Px::category('Kitchens');
        $id = shopPagesProduct($category)['product'];
        $old = shopPagesSlug('product', $id);
        shopPagesRename('product', $id, 'اسم-جديد', 'new-name');
        Fx::asSystem(fn () => app(DeactivateCategoryHandler::class)->handle(new DeactivateCategory($category, 'HIDE')));

        $moved = get("/sa/ar/products/{$old}")->assertStatus(301)->assertRedirect(url('/sa/ar/products/'.rawurlencode('اسم-جديد')));

        get((string) $moved->headers->get('Location'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('available', false));
    });

    it('answers what has no page of its own exactly as a slug never held: status, page, props and headers', function (string $path, string $kind, Closure $make) {
        $id = $make();
        $en = shopPagesSlug($kind, $id);
        $ar = shopPagesSlug($kind, $id, 'ar');

        // Its own address has no page either.
        get("/sa/en/{$path}/{$en}")->assertNotFound();
        get("/sa/ar/{$path}/".rawurlencode($ar))->assertNotFound();

        $unknownEn = str_repeat('x', strlen($en));
        $unknownAr = str_repeat('ي', mb_strlen($ar));

        expect(shopPagesAnswer("/sa/ar/{$path}/{$en}"))->toEqual(shopPagesAnswer("/sa/ar/{$path}/{$unknownEn}"))
            ->and(shopPagesAnswer("/sa/en/{$path}/".rawurlencode($ar)))->toEqual(shopPagesAnswer("/sa/en/{$path}/".rawurlencode($unknownAr)))
            ->and(shopPagesAnswer("/sa/ar/{$path}/{$en}")['status'])->toBe(404)
            ->and(shopPagesAnswer("/sa/ar/{$path}/{$en}")['component'])->toBe('Catalog/Storefront/NotFound');
    })->with([
        'a draft product' => ['products', 'product', fn (): string => Px::product('Draft')],
        'a product archived from a draft, never shown' => ['products', 'product', function (): string {
            $id = Px::product('Abandoned');
            Fx::asSystem(fn () => app(ArchiveProductHandler::class)->handle(new ArchiveProduct($id)));

            return $id;
        }],
        'a category listing nothing here' => ['categories', 'category', fn (): string => Px::category('Empty')],
        'a category switched off' => ['categories', 'category', function (): string {
            $category = Px::category('Kitchens');
            shopPagesProduct($category);
            Fx::asSystem(fn () => app(DeactivateCategoryHandler::class)->handle(new DeactivateCategory($category, 'HIDE')));

            return $category;
        }],
        'an inactive brand' => ['brands', 'brand', function (): string {
            $brand = shopPagesBrandOf(shopPagesProduct(Px::category('Kitchens'))['product']);
            Fx::asSystem(fn () => app(DeactivateBrandHandler::class)->handle(new DeactivateBrand($brand, 'HIDE')));

            return $brand;
        }],
    ]);

    it('answers 404 for an old slug of what has no page', function () {
        $category = Px::category('Kitchens');
        shopPagesProduct($category);
        $old = shopPagesSlug('category', $category);
        shopPagesRename('category', $category, en: 'new-name');
        Fx::asSystem(fn () => app(DeactivateCategoryHandler::class)->handle(new DeactivateCategory($category, 'HIDE')));

        get("/sa/ar/categories/{$old}")->assertNotFound();
    });

    it('forwards a company-only product for each viewer, and its category only for those it lists for', function () {
        $category = Px::category('Kitchens');
        $id = shopPagesProduct($category)['product'];
        $en = shopPagesSlug('product', $id);
        $ar = rawurlencode(shopPagesSlug('product', $id, 'ar'));
        $categoryEn = shopPagesSlug('category', $category);
        $categoryAr = rawurlencode(shopPagesSlug('category', $category, 'ar'));
        Fx::asSystem(fn () => app(SetCategoryAudiencesHandler::class)->handle(new SetCategoryAudiences(Fx::storeId('sa'), $category, false)));

        foreach ([ShopperKind::Guest, ShopperKind::Company] as $viewer) {
            shopPagesViewer($viewer);
            get("/sa/ar/products/{$en}")->assertStatus(301)->assertRedirect(url("/sa/ar/products/{$ar}"));
            get("/sa/ar/products/{$ar}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('available', true));
            get("/sa/ar/categories/{$categoryEn}")->assertStatus(301)->assertRedirect(url("/sa/ar/categories/{$categoryAr}"));
            get("/sa/ar/categories/{$categoryAr}")->assertOk();
        }

        shopPagesViewer(ShopperKind::Individual);
        // The product's own address opens for an individual, on "Not available now"; its category's does not.
        get("/sa/ar/products/{$en}")->assertStatus(301)->assertRedirect(url("/sa/ar/products/{$ar}"));
        get("/sa/ar/products/{$ar}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('available', false));
        get("/sa/ar/categories/{$categoryAr}")->assertNotFound();
        get("/sa/ar/categories/{$categoryEn}")->assertNotFound();
    });

    it('forwards a wholesale-only product for each viewer, and its category only for those it lists for', function () {
        $category = Px::category('Kitchens');
        $p = shopPagesProduct($category);
        $id = $p['product'];
        $en = shopPagesSlug('product', $id);
        $ar = rawurlencode(shopPagesSlug('product', $id, 'ar'));
        $categoryEn = shopPagesSlug('category', $category);
        $categoryAr = rawurlencode(shopPagesSlug('category', $category, 'ar'));
        Fx::asSystem(fn () => app(SetSellingTermsHandler::class)->handle(new SetSellingTerms(Fx::storeId('sa'), $id, [$p['variants'][0] => ['retail' => false, 'wholesale' => true]], wholesaleMinimum: 10)));

        shopPagesViewer(ShopperKind::Company);
        get("/sa/ar/products/{$en}")->assertStatus(301)->assertRedirect(url("/sa/ar/products/{$ar}"));
        get("/sa/ar/products/{$ar}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('available', true)->where('variants.0.sellsWholesale', true));
        get("/sa/ar/categories/{$categoryEn}")->assertStatus(301)->assertRedirect(url("/sa/ar/categories/{$categoryAr}"));

        shopPagesViewer(ShopperKind::Individual);
        get("/sa/ar/products/{$en}")->assertStatus(301)->assertRedirect(url("/sa/ar/products/{$ar}"));
        get("/sa/ar/products/{$ar}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('available', false));
        get("/sa/ar/categories/{$categoryAr}")->assertNotFound();
        get("/sa/ar/categories/{$categoryEn}")->assertNotFound();
    });

    it('forwards in a store that has not chosen the product, and a category only where it lists something', function () {
        $category = Px::category('Kitchens');
        $id = shopPagesProduct($category)['product'];
        $en = shopPagesSlug('product', $id);
        $ar = rawurlencode(shopPagesSlug('product', $id, 'ar'));
        $categoryEn = shopPagesSlug('category', $category);

        // Chosen in Saudi Arabia only.
        get("/eg/ar/products/{$ar}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('available', false));
        get("/eg/ar/products/{$en}")->assertStatus(301)->assertRedirect(url("/eg/ar/products/{$ar}"));
        get("/eg/ar/categories/{$categoryEn}")->assertNotFound();
    });

    it('tells a browser not to keep these moves, and only these', function (string $path, string $kind, Closure $make) {
        $id = $make();
        $oldEn = shopPagesSlug($kind, $id);

        expect(get("/sa/ar/{$path}/{$oldEn}")->assertStatus(301)->headers->get('Cache-Control'))->toBe('no-cache, private');

        shopPagesRename($kind, $id, en: 'new-name');

        // The other language's old slug is still across the languages; the page's own old slug stays true for good.
        expect(get("/sa/ar/{$path}/{$oldEn}")->assertStatus(301)->headers->get('Cache-Control'))->toBe('no-cache, private')
            ->and(get("/sa/en/{$path}/{$oldEn}")->assertStatus(301)->headers->get('Cache-Control'))->toBeNull();
    })->with('the three kinds');

    it('lets such a move stop being true: a new item takes the slug in the page\'s language', function (string $path, string $kind, Closure $make) {
        $arabic = $make();
        shopPagesRename($kind, $arabic, ar: '60-80');
        $en = shopPagesSlug($kind, $arabic);

        get("/sa/en/{$path}/60-80")->assertStatus(301)->assertRedirect(url("/sa/en/{$path}/{$en}"));

        $english = $make();
        shopPagesRename($kind, $english, en: '60-80');

        get("/sa/en/{$path}/60-80")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('slug', '60-80'));
    })->with('the three kinds');

    it('lets the page\'s language win when two items share a slug across the languages', function (string $path, string $kind, Closure $make) {
        // Only digits, with or without hyphens, can be both: an Arabic slug takes Arabic letters, an English one Latin (§5.3).
        $english = $make();
        $arabic = $make();
        shopPagesRename($kind, $english, en: '60-80');
        shopPagesRename($kind, $arabic, ar: '60-80');

        get("/sa/en/{$path}/60-80")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('slug', '60-80')
            ->where('address.alternate', url("/sa/ar/{$path}/".rawurlencode(shopPagesSlug($kind, $english, 'ar')))));
        get("/sa/ar/{$path}/60-80")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('slug', '60-80')
            ->where('address.alternate', url("/sa/en/{$path}/".shopPagesSlug($kind, $arabic))));
    })->with('the three kinds');

    it('lets the page\'s language win when its holder has no page: 404, never the other language\'s item', function () {
        $shown = shopPagesProduct(Px::category('Kitchens'))['product'];
        shopPagesRename('product', $shown, ar: '2026');
        Fx::asSystem(fn () => app(CreateProductHandler::class)->handle(new CreateProduct('درج مسودة', 'Draft drawer', slugEn: '2026')));

        get('/sa/en/products/2026')->assertNotFound();
        get('/sa/ar/products/2026')->assertOk();
    });

    it('lets the page\'s language win when its holder held it before: to its own current slug', function () {
        $held = shopPagesProduct(Px::category('Kitchens'))['product'];
        $shown = shopPagesProduct(Px::category('Kitchens'))['product'];
        shopPagesRename('product', $held, en: '2026');
        shopPagesRename('product', $held, en: 'new-name');
        shopPagesRename('product', $shown, ar: '2026');

        get('/sa/en/products/2026')->assertStatus(301)->assertRedirect(url('/sa/en/products/new-name'));
        get('/sa/ar/products/2026')->assertOk();
    });

    it('answers 404 for an item with no slug in the page\'s language', function (string $path, string $kind, Closure $make) {
        $id = $make();
        $ar = rawurlencode(shopPagesSlug($kind, $id, 'ar'));
        // No form leaves a shown item so - only a draft, below - hence by hand.
        DB::table("catalog.{$kind}_slugs")->where("{$kind}_id", $id)->where('locale', 'en')->delete();

        get("/sa/en/{$path}/{$ar}")->assertNotFound();
    })->with('the three kinds');

    it('answers 404 for a draft with its Arabic name only, under English', function () {
        $draft = Px::product(null);

        get('/sa/en/products/'.rawurlencode(shopPagesSlug('product', $draft, 'ar')))->assertNotFound();
    });

    it('asks a page shown nothing more, and a slug no one holds here one read', function () {
        config(['session.lottery' => [0, 100]]);
        $category = Px::category('Kitchens');
        $p = shopPagesProduct($category, ['60 cm', '80 cm']);
        shopPagesProduct($category, ['60 cm']);
        $brand = shopPagesBrandOf($p['product']);
        $pages = [
            'category en' => '/sa/en/categories/'.shopPagesSlug('category', $category),
            'category ar' => '/sa/ar/categories/'.rawurlencode(shopPagesSlug('category', $category, 'ar')),
            'brand en' => '/sa/en/brands/'.shopPagesSlug('brand', $brand),
            'brand ar' => '/sa/ar/brands/'.rawurlencode(shopPagesSlug('brand', $brand, 'ar')),
            'product en' => '/sa/en/products/'.shopPagesSlug('product', $p['product']),
            'product ar' => '/sa/ar/products/'.rawurlencode(shopPagesSlug('product', $p['product'], 'ar')),
        ];
        // The read amendment 38 adds: the only one comparing the current slug's language to a value.
        $reads = static fn (): int => count(array_filter(DB::getQueryLog(), static fn (array $query): bool => str_contains((string) $query['query'], '"now"."locale" = ?')));

        foreach ($pages as $url) {
            app()->forgetScopedInstances();
            DB::flushQueryLog();
            DB::enableQueryLog();
            get($url)->assertOk();
            DB::disableQueryLog();
            expect($reads())->toBe(0);
        }

        // The English pages' counts are the budget's above, held since before amendment 38 (a product in stock, amendment 39).
        expect(array_map(shopPagesQueries(...), $pages))->toBe(['category en' => 6, 'category ar' => 6, 'brand en' => 6, 'brand ar' => 6, 'product en' => 14, 'product ar' => 14]);

        foreach (['products', 'categories', 'brands'] as $path) {
            app()->forgetScopedInstances();
            DB::flushQueryLog();
            DB::enableQueryLog();
            get("/sa/en/{$path}/no-such-page")->assertNotFound();
            DB::disableQueryLog();
            expect($reads())->toBe(1);
        }

        // A slug no one holds: this language's read and the other's, 2. One the other language holds: a
        // third, checking its address, whichever way it then answers; for a category listing nothing, a fourth:
        // whether only stock or a price emptied it (amendment 39). A page shown asks neither.
        $queries = static function (string $url): int {
            app()->forgetScopedInstances();
            DB::flushQueryLog();
            DB::enableQueryLog();
            get($url)->assertNotFound();
            DB::disableQueryLog();

            return count(DB::getQueryLog());
        };
        $inactive = Px::brand('Inactive');
        Fx::asSystem(fn () => app(DeactivateBrandHandler::class)->handle(new DeactivateBrand($inactive, 'HIDE')));
        $noPage = [
            'products' => shopPagesSlug('product', Px::product('Draft'), 'ar'),
            'categories' => shopPagesSlug('category', Px::category('Empty'), 'ar'),
            'brands' => shopPagesSlug('brand', $inactive, 'ar'),
        ];

        foreach ($noPage as $path => $slug) {
            // Warm first, as the rest of the budget is measured.
            $queries("/sa/en/{$path}/no-such-page");
            $unknown = $queries("/sa/en/{$path}/no-such-page");

            expect($queries("/sa/en/{$path}/".rawurlencode($slug)))->toBe($unknown + ($path === 'categories' ? 2 : 1));
        }
    });
});
