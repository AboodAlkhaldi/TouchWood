<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Modules\Catalog\Application\CatalogPermissions as P;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Symfony\Component\HttpFoundation\Response;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\AdminBrowser;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Catalog\Support\CatalogFixtures as Cx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;

use function Pest\Laravel\seed;

/*
| Catalog's products screens over HTTP (catalog.md §4.4 S8, S9, amendment 13): who opens the list and a
| product's page - every product to whoever reads products in some store (P4) -, the list's store
| filter, adding a draft without a store (13(f)), every change a product's tabs make - said where the
| panel says it, refused to a reader without the job in every store where the product is on -, photos
| uploaded from the page (P5), and each page's own queries, recorded under the admin budget of 15.
|
| The use cases and the reads have their own tests; these are about the screens.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
    Storage::fake('local', ['serve' => true]);
    Storage::fake('public');
    Queue::fake();
});

/**
 * Signed in through the panel's door ('*' for All stores).
 *
 * @param  list<string>  $permissions
 * @param  list<string>  $stores
 */
function catalogProductScreens(array $permissions, array $stores = ['*']): AdminBrowser
{
    $staffId = Fx::staffWith($permissions, $stores);
    $browser = new AdminBrowser('10.9.2.'.random_int(20, 250));

    $browser->post('/admin/sign-in', [
        'email' => (string) DB::table('access.staff_users')->where('id', $staffId)->value('email'),
        'password' => Fx::STAFF_PASSWORD,
    ])->assertRedirect('/admin/sign-in/code');

    $browser->post('/admin/sign-in/code', ['code' => RecordingSecurityMessages::installed()->lastCode()])->assertRedirect('/admin');

    return $browser;
}

/**
 * @param  TestResponse<Response>  $response
 * @return array<string, mixed>
 */
function catalogProductScreensErrors(TestResponse $response): array
{
    $errors = AdminBrowser::flashed($response, 'errors');

    if ($errors instanceof ViewErrorBag) {
        return $errors->getBag('default')->toArray();
    }

    return is_array($errors) ? ($errors['default']['messages'] ?? []) : [];
}

/**
 * @param  TestResponse<Response>  $response
 */
function catalogProductScreensToast(TestResponse $response): mixed
{
    return AdminBrowser::flashed($response, 'status');
}

function catalogProductScreensNotYours(): string
{
    return trans('errors.unauthorized.detail', [], 'en');
}

function catalogProductScreensOn(string $store, string $productId): void
{
    Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId($store), $productId, true)));
}

/**
 * How many queries the page asks for itself, opened a second time (warm): those made while Catalog's
 * own code is running - the panel's frame around it is counted apart (CatalogListScreensTest).
 */
function catalogProductScreensOwnQueries(AdminBrowser $browser, string $uri): int
{
    $browser->get($uri)->assertOk();
    /** @var ArrayObject<int, string> $own */
    $own = new ArrayObject;

    DB::listen(function (QueryExecuted $query) use ($own): void {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $frame) {
            if (str_contains(str_replace(chr(92), '/', $frame['file'] ?? ''), '/src/Modules/Catalog/')) {
                $own[] = $query->sql;

                return;
            }
        }
    });

    $browser->get($uri)->assertOk();

    return count($own);
}

describe('the products list', function () {
    it('is offered in the menu and opens for whoever reads products in some store, newest first, and for no one else', function () {
        $older = Px::product('Hinge');
        $newer = Px::product('Rail');
        $browser = catalogProductScreens([P::PRODUCT_VIEW], ['eg']);

        $browser->get('/admin/products')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Catalog/Admin/Products/Index')
                ->where('products.0.id', $newer)
                ->where('products.1.id', $older)
                ->where('storeCode', null)
                ->where('mayCreate', false)
                ->has('brands')
            );

        catalogProductScreens([P::BRAND_MANAGE])->get('/admin/products')->assertForbidden();
    });

    it('names the stores where each product is on among those the reader covers, and one store\'s state when it is chosen', function () {
        ['product' => $product] = Px::ready(['60 cm', '80 cm']);
        catalogProductScreensOn('sa', $product);
        catalogProductScreensOn('eg', $product);
        $browser = catalogProductScreens([P::PRODUCT_VIEW], ['eg']);

        $browser->get('/admin/products')->assertInertia(fn (AssertableInertia $page) => $page->where('products.0.onIn', ['eg']));
        $browser->get('/admin/products?store=eg&state=ON')
            ->assertInertia(fn (AssertableInertia $page) => $page->where('storeCode', 'eg')->where('storeState', 'ON')->where('products.0.storeState', 'ON')->where('products.0.storeVariantsOn', 2));
        $browser->get('/admin/products?store=eg&state=NOT_CHOSEN')->assertInertia(fn (AssertableInertia $page) => $page->has('products', 0));
        $browser->get('/admin/products?store=sa')->assertForbidden();
        $browser->get('/admin/products?store=zz')->assertForbidden();
    });

    it('finds by a name or a code, and shows the next page after the last row', function () {
        ['product' => $drawer, 'variants' => [$variant]] = Px::ready();
        $code = (string) DB::table('catalog.variants')->where('id', $variant)->value('code');
        $products = array_map(fn (int $n): string => Px::product("Item {$n}"), range(1, 51));
        $browser = catalogProductScreens([P::PRODUCT_VIEW]);

        $browser->get("/admin/products?q={$code}")->assertInertia(fn (AssertableInertia $page) => $page->has('products', 1)->where('products.0.id', $drawer)->where('search', $code));
        $browser->get('/admin/products?q=item')->assertInertia(fn (AssertableInertia $page) => $page->has('products', 50)->where('more', true)->where('after', $products[1]));
        $browser->get("/admin/products?q=item&after={$products[1]}")->assertInertia(fn (AssertableInertia $page) => $page->has('products', 1)->where('products.0.id', $products[0])->where('more', false)->where('after', null));
    });

    it('adds a draft asking no store, and its page opens; says an empty Arabic name beside it; refuses anyone without the job', function () {
        $brand = Px::brand('Hettich');
        $browser = catalogProductScreens([P::PRODUCT_CREATE, P::PRODUCT_VIEW], ['eg']);

        $added = $browser->post('/admin/products', ['name_ar' => 'درج جديد', 'name_en' => 'New Drawer', 'brand_id' => $brand]);
        $id = (string) DB::table('catalog.products')->where('name_en', 'New Drawer')->value('id');

        $added->assertRedirect("/admin/products/{$id}");
        expect(catalogProductScreensToast($added))->toBe('Product added')
            ->and(DB::table('catalog.products')->where('id', $id)->value('brand_id'))->toBe($brand)
            ->and(catalogProductScreensErrors($browser->post('/admin/products', ['name_ar' => ''])))->toHaveKey('name_ar')
            ->and(AdminBrowser::formError(catalogProductScreens([P::PRODUCT_VIEW])->post('/admin/products', ['name_ar' => 'درج'])))->toBe(catalogProductScreensNotYours())
            ->and(DB::table('catalog.products')->count())->toBe(1);
    });
});

describe('a product\'s page', function () {
    it('opens on its Details with what it lacks, and each tab with its own data', function () {
        $draft = Px::product('Hinge');
        $browser = catalogProductScreens([P::PRODUCT_VIEW]);

        $browser->get("/admin/products/{$draft}")
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Catalog/Admin/Products/Show')
                ->where('product.id', $draft)
                ->where('tab', 'details')
                ->where('missing', ['description_ar', 'description_en', 'category', 'variants', 'photos'])
                ->has('brands')
                ->where('variants', null)
            );

        $browser->get("/admin/products/{$draft}?tab=variants")->assertInertia(fn (AssertableInertia $page) => $page->where('tab', 'variants')->where('variants', [])->has('attributes')->where('brands', null));
        $browser->get("/admin/products/{$draft}?tab=search")->assertInertia(fn (AssertableInertia $page) => $page->where('searchWords', [])->where('filterValueIds', []));
        $browser->get("/admin/products/{$draft}?tab=related")->assertInertia(fn (AssertableInertia $page) => $page->where('related', [])->where('found', null));
        $browser->get('/admin/products/01k0000000000000000000zzzz')->assertNotFound();
        catalogProductScreens([P::BRAND_MANAGE])->get("/admin/products/{$draft}")->assertForbidden();
    });

    it('finds ready products to relate, never the product itself', function () {
        ['product' => $product] = Px::ready();
        ['product' => $other] = Px::ready();
        $code = (string) DB::table('catalog.variants')->where('product_id', $other)->value('code');
        $own = (string) DB::table('catalog.variants')->where('product_id', $product)->value('code');
        $browser = catalogProductScreens([P::PRODUCT_VIEW]);

        $browser->get("/admin/products/{$product}?tab=related&find={$code}")->assertInertia(fn (AssertableInertia $page) => $page->has('found', 1)->where('found.0.id', $other));
        $browser->get("/admin/products/{$product}?tab=related&find={$own}")->assertInertia(fn (AssertableInertia $page) => $page->where('found', []));
    });

    it('is read only to someone without the job in every store where it is on', function () {
        ['product' => $product] = Px::ready();
        catalogProductScreensOn('eg', $product);
        $browser = catalogProductScreens([P::PRODUCT_VIEW, P::PRODUCT_UPDATE], ['sa']);

        $browser->get("/admin/products/{$product}")->assertInertia(fn (AssertableInertia $page) => $page->where('mayUpdate', false));

        expect(AdminBrowser::formError($browser->post("/admin/products/{$product}/search-words", ['words' => ['rail']])))->toBe(catalogProductScreensNotYours())
            ->and(DB::table('catalog.product_search_words')->where('product_id', $product)->count())->toBe(0);
    });
});

describe('a product\'s changes', function () {
    it('saves its details, and says an empty Arabic name beside it', function () {
        $draft = Px::product('Hinge');
        $brand = (string) DB::table('catalog.products')->where('id', $draft)->value('brand_id');
        $category = Px::category('Drawers');
        $browser = catalogProductScreens([P::PRODUCT_VIEW, P::PRODUCT_UPDATE]);
        $form = ['name_ar' => 'مفصلة', 'name_en' => 'Soft Hinge', 'brand_id' => $brand, 'category_id' => $category, 'description_ar' => 'وصف.', 'description_en' => "Quiet.\n\n- Soft close"];

        expect(catalogProductScreensToast($browser->post("/admin/products/{$draft}/details", $form)))->toBe('Details saved')
            ->and(DB::table('catalog.products')->where('id', $draft)->first(['name_en', 'category_id']))->toEqual((object) ['name_en' => 'Soft Hinge', 'category_id' => $category])
            ->and(json_decode((string) DB::table('catalog.products')->where('id', $draft)->value('description_en'), true)['blocks'][1]['type'] ?? null)->toBe('list')
            ->and(catalogProductScreensErrors($browser->post("/admin/products/{$draft}/details", [...$form, 'name_ar' => ''])))->toHaveKey('name_ar');
    });

    it('adds a variant with its values, edits it, archives, restores and deletes a draft\'s', function () {
        $width = Px::attribute('Width');
        $sixty = Px::value($width, '60 cm');
        $eighty = Px::value($width, '80 cm');
        $set = Px::set([$width]);
        $draft = Px::product('Drawer');
        DB::table('catalog.products')->where('id', $draft)->update(['attribute_set_id' => $set]);
        $browser = catalogProductScreens([P::PRODUCT_VIEW, P::PRODUCT_UPDATE]);

        expect(catalogProductScreensErrors($browser->post("/admin/products/{$draft}/variants", ['code' => 'abc', 'values' => [$width => $sixty]])))->toHaveKey('code')
            ->and(catalogProductScreensToast($browser->post("/admin/products/{$draft}/variants", ['code' => '7001', 'values' => [$width => $sixty], 'weight_grams' => '450', 'position' => '10'])))->toBe('Variant added');

        $variant = (string) DB::table('catalog.variants')->where('product_id', $draft)->value('id');

        expect(DB::table('catalog.variants')->where('id', $variant)->value('weight_grams'))->toBe(450)
            ->and(catalogProductScreensToast($browser->post("/admin/products/{$draft}/variants/{$variant}", ['code' => '7002', 'values' => [$width => $eighty]])))->toBe('Variant saved')
            ->and(DB::table('catalog.variants')->where('id', $variant)->value('code'))->toBe('7002')
            ->and(DB::table('catalog.variant_values')->where('variant_id', $variant)->value('value_id'))->toBe($eighty)
            ->and(catalogProductScreensToast($browser->post("/admin/products/{$draft}/variants/{$variant}/archive")))->toBe('Variant archived')
            ->and(catalogProductScreensToast($browser->post("/admin/products/{$draft}/variants/{$variant}/restore")))->toBe('Variant restored')
            ->and(catalogProductScreensToast($browser->post("/admin/products/{$draft}/variants/{$variant}/delete")))->toBe('Variant deleted')
            ->and(DB::table('catalog.variants')->where('id', $variant)->exists())->toBeFalse();
    });

    it('corrects a ready product\'s code, only for the code\'s job', function () {
        ['product' => $product, 'variants' => [$variant]] = Px::ready();

        expect(AdminBrowser::formError(catalogProductScreens([P::PRODUCT_VIEW, P::PRODUCT_UPDATE])->post("/admin/products/{$product}/variants/{$variant}/code", ['code' => '9001'])))->toBe(catalogProductScreensNotYours());

        $browser = catalogProductScreens([P::PRODUCT_VIEW, P::VARIANT_CORRECT_CODE]);

        expect(catalogProductScreensToast($browser->post("/admin/products/{$product}/variants/{$variant}/code", ['code' => '9001'])))->toBe('Code corrected')
            ->and(DB::table('catalog.variants')->where('id', $variant)->value('code'))->toBe('9001');
    });

    it('uploads photos into the gallery after those kept, reorders and removes them, and refuses a file that is no image', function () {
        $draft = Px::product('Drawer');
        $kept = Cx::media();
        DB::table('catalog.product_photos')->insert(['product_id' => $draft, 'media_id' => $kept, 'position' => 1]);
        $browser = catalogProductScreens([P::PRODUCT_VIEW, P::PRODUCT_UPDATE]);
        $gallery = fn (): array => DB::table('catalog.product_photos')->where('product_id', $draft)->orderBy('position')->pluck('media_id')->all();

        expect(catalogProductScreensToast($browser->post("/admin/products/{$draft}/gallery", ['media_ids' => [$kept], 'photos' => [UploadedFile::fake()->image('a.jpg', 400, 300), UploadedFile::fake()->image('b.png', 300, 300)]])))->toBe('Photos saved')
            ->and($gallery())->toHaveCount(3)
            ->and($gallery()[0])->toBe($kept);

        $added = $gallery();

        expect(catalogProductScreensToast($browser->post("/admin/products/{$draft}/gallery", ['media_ids' => [$added[2], $added[0]]])))->toBe('Photos saved')
            ->and($gallery())->toBe([$added[2], $added[0]])
            ->and(AdminBrowser::formError($browser->post("/admin/products/{$draft}/gallery", ['media_ids' => $gallery(), 'photos' => [UploadedFile::fake()->create('x.pdf', 20, 'application/pdf')]])))
            ->toBe(trans('platform::errors.unsupported_media_type.detail', [], 'en'))
            ->and($gallery())->toBe([$added[2], $added[0]]);
    });

    it('gives a variant its own photos', function () {
        ['product' => $product, 'variants' => [$variant]] = Px::ready();
        $browser = catalogProductScreens([P::PRODUCT_VIEW, P::PRODUCT_UPDATE]);

        expect(catalogProductScreensToast($browser->post("/admin/products/{$product}/variants/{$variant}/photos", ['photos' => [UploadedFile::fake()->image('v.jpg', 300, 300)]])))->toBe('Variant photos saved')
            ->and(DB::table('catalog.variant_photos')->where('variant_id', $variant)->count())->toBe(1);
    });

    it('saves search words, filters and related products, each in its order', function () {
        ['product' => $product] = Px::ready();
        ['product' => $other] = Px::ready();
        $finish = Px::attribute('Finish', 'FILTERABLE');
        $matte = Px::value($finish, 'Matte');
        $browser = catalogProductScreens([P::PRODUCT_VIEW, P::PRODUCT_UPDATE]);

        expect(catalogProductScreensToast($browser->post("/admin/products/{$product}/search-words", ['words' => ['hinge', 'مفصلة']])))->toBe('Search words saved')
            ->and(DB::table('catalog.product_search_words')->where('product_id', $product)->orderBy('position')->pluck('word')->all())->toBe(['hinge', 'مفصلة'])
            ->and(catalogProductScreensToast($browser->post("/admin/products/{$product}/filters", ['value_ids' => [$matte]])))->toBe('Filters saved')
            ->and(DB::table('catalog.product_filter_values')->where('product_id', $product)->value('value_id'))->toBe($matte)
            ->and(catalogProductScreensToast($browser->post("/admin/products/{$product}/related/goes_with", ['product_ids' => [$other]])))->toBe('Related products saved')
            ->and(DB::table('catalog.product_relations')->where('product_id', $product)->where('kind', 'GOES_WITH')->value('related_id'))->toBe($other);
    });

    it('refuses making a draft ready while it lacks something, makes a complete one ready, archives and restores it', function () {
        $empty = Px::product('Hinge');
        ['product' => $product] = Px::ready();
        DB::table('catalog.products')->where('id', $product)->update(['stage' => 'DRAFT']);
        $browser = catalogProductScreens([P::PRODUCT_VIEW, P::PRODUCT_PUBLISH, P::PRODUCT_ARCHIVE]);

        expect(AdminBrowser::formError($browser->post("/admin/products/{$empty}/ready")))->toBe(trans('catalog::errors.product_not_ready.detail', [], 'en'))
            ->and(catalogProductScreensToast($browser->post("/admin/products/{$product}/ready")))->toBe('Product made ready')
            ->and(catalogProductScreensToast($browser->post("/admin/products/{$product}/archive")))->toBe('Product archived')
            ->and(DB::table('catalog.products')->where('id', $product)->value('stage'))->toBe('ARCHIVED')
            ->and(catalogProductScreensToast($browser->post("/admin/products/{$product}/restore")))->toBe('Product restored')
            ->and(DB::table('catalog.products')->where('id', $product)->value('stage'))->toBe('READY');
    });

    it('deletes a draft whole and goes back to the list', function () {
        $draft = Px::product('Hinge');
        $browser = catalogProductScreens([P::PRODUCT_VIEW, P::PRODUCT_ARCHIVE]);

        $deleted = $browser->post("/admin/products/{$draft}/delete");

        $deleted->assertRedirect('/admin/products');
        expect(catalogProductScreensToast($deleted))->toBe('Draft deleted')
            ->and(DB::table('catalog.products')->where('id', $draft)->exists())->toBeFalse();
    });
});

describe('the query budget (frontend.md §5, P21)', function () {
    it('opens each page in its own recorded number of queries, warm, however many rows', function (string $page, int $recorded) {
        ['product' => $product] = Px::ready(['60 cm', '80 cm']);
        ['product' => $other] = Px::ready();
        catalogProductScreensOn('sa', $product);
        $browser = catalogProductScreens([P::PRODUCT_VIEW, P::PRODUCT_CREATE, P::PRODUCT_UPDATE, P::PRODUCT_PUBLISH, P::PRODUCT_ARCHIVE, P::VARIANT_CORRECT_CODE]);
        $uri = str_replace(['{product}', '{code}'], [$product, (string) DB::table('catalog.variants')->where('product_id', $other)->value('code')], $page);
        $own = catalogProductScreensOwnQueries($browser, $uri);

        expect($own)->toBe($recorded)->and($own)->toBeLessThanOrEqual(15);

        // More of everything the pages show: never one query a row.
        foreach (range(1, 5) as $n) {
            Px::ready();
            Px::product("Draft {$n}");
            DB::table('catalog.product_photos')->insert(['product_id' => $product, 'media_id' => Cx::media(), 'position' => 10 + $n]);
        }

        expect(catalogProductScreensOwnQueries($browser, $uri))->toBe($recorded);
    })->with([
        'the list' => ['/admin/products', 13],
        'the list, one store' => ['/admin/products?store=sa&state=ON', 13],
        'details' => ['/admin/products/{product}', 12],
        'variants' => ['/admin/products/{product}?tab=variants', 13],
        'photos' => ['/admin/products/{product}?tab=photos', 11],
        'search and filters' => ['/admin/products/{product}?tab=search', 13],
        'related, finding' => ['/admin/products/{product}?tab=related&find={code}', 13],
    ]);
});
