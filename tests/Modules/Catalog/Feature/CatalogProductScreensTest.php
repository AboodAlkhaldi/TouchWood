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
use Modules\Catalog\Application\Command\SetFilterValues\SetFilterValues;
use Modules\Catalog\Application\Command\SetFilterValues\SetFilterValuesHandler;
use Modules\Catalog\Application\Command\SetRelations\SetRelations;
use Modules\Catalog\Application\Command\SetRelations\SetRelationsHandler;
use Modules\Catalog\Application\Command\SetSearchWords\SetSearchWords;
use Modules\Catalog\Application\Command\SetSearchWords\SetSearchWordsHandler;
use Modules\Catalog\Application\Command\SetVariantPhotos\SetVariantPhotos;
use Modules\Catalog\Application\Command\SetVariantPhotos\SetVariantPhotosHandler;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStore;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStoreHandler;
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
 * @param  array<string, list<string>>  $exceptions  a job held only in these of the stores
 */
function catalogProductScreens(array $permissions, array $stores = ['*'], array $exceptions = []): AdminBrowser
{
    $staffId = Fx::staffWith($permissions, $stores, exceptions: $exceptions);
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

/** Digits as typed on an Arabic keyboard (U+0660-0669), built here so no file holds them. */
function catalogProductScreensIndic(string $digits): string
{
    return implode('', array_map(static fn (string $digit): string => mb_chr(0x0660 + (int) $digit), str_split($digits)));
}

/**
 * The menu entry keys the panel offers on a page, as "group/key".
 *
 * @param  TestResponse<Response>  $response
 * @return list<string>
 */
function catalogProductScreensMenu(TestResponse $response): array
{
    $offered = [];

    $response->assertInertia(function (AssertableInertia $page) use (&$offered): void {
        foreach ($page->toArray()['props']['menu'] as $group) {
            foreach ($group['entries'] as $entry) {
                $offered[] = $group['key'].'/'.$entry['key'];
            }
        }
    });

    return $offered;
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
        $opened = $browser->get('/admin/products');

        $opened
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Catalog/Admin/Products/Index')
                ->where('products.0.id', $newer)
                ->where('products.1.id', $older)
                ->where('storeCode', null)
                ->where('mayCreate', false)
                ->has('brands')
            );
        expect(catalogProductScreensMenu($opened))->toContain('catalog/products')
            ->and(catalogProductScreensMenu(catalogProductScreens([P::BRAND_MANAGE])->get('/admin/brands')))->not->toContain('catalog/products');

        catalogProductScreens([P::BRAND_MANAGE])->get('/admin/products')->assertForbidden();
    });

    it('offers Add Product to someone holding the job in a store that is on, and no longer once that store is off', function () {
        $browser = catalogProductScreens([P::PRODUCT_VIEW, P::PRODUCT_CREATE], ['eg', 'ae'], [P::PRODUCT_CREATE => ['ae']]);

        $browser->get('/admin/products')->assertInertia(fn (AssertableInertia $page) => $page->where('mayCreate', true));

        Fx::asSystem(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore('ae')));

        $browser->get('/admin/products')->assertInertia(fn (AssertableInertia $page) => $page->where('mayCreate', false));
        expect(AdminBrowser::formError($browser->post('/admin/products', ['name_ar' => 'درج'])))->toBe(catalogProductScreensNotYours())
            ->and(DB::table('catalog.products')->count())->toBe(0);
    });

    it('shows the filters as it applied them: digits typed in any script read 0-9, what is not one left out', function () {
        ['product' => $product, 'variants' => [$variant]] = Px::ready();
        $code = (string) DB::table('catalog.variants')->where('id', $variant)->value('code');
        $browser = catalogProductScreens([P::PRODUCT_VIEW]);

        $browser->get('/admin/products?q='.urlencode(catalogProductScreensIndic($code)).'&stage=SOLD&state=ON&category=x')
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('search', $code)
                ->where('stage', null)
                ->where('storeState', null)
                ->where('categoryId', 'x')
                ->has('products', 0)
            );
        $browser->get('/admin/products?q='.urlencode(catalogProductScreensIndic($code)))->assertInertia(fn (AssertableInertia $page) => $page->has('products', 1)->where('products.0.id', $product));
        // A % or a _ is a character looked for, not a pattern; bytes that are not text find nothing and never fail.
        $browser->get('/admin/products?q=%25')->assertInertia(fn (AssertableInertia $page) => $page->has('products', 0));
        $browser->get('/admin/products?q=_')->assertInertia(fn (AssertableInertia $page) => $page->has('products', 0));
        $browser->get('/admin/products?q=%FF')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('products', 0));
    });

    it('offers every brand and category products are in, saying which are active; Details offers the active ones', function () {
        // The first brand made is the default, which stays active: the one switched off comes second.
        Px::brand('Blum');
        $off = Px::brand('Old Brand');
        $draft = Px::product('Hinge', $off);
        Px::warranty();
        DB::table('catalog.brands')->where('id', $off)->update(['is_active' => false]);
        $closed = Px::category('Closed');
        DB::table('catalog.categories')->where('id', $closed)->update(['is_active' => false]);
        $browser = catalogProductScreens([P::PRODUCT_VIEW]);

        $browser->get('/admin/products')->assertInertia(fn (AssertableInertia $page) => $page
            ->where('brands', fn ($brands): bool => $brands->firstWhere('id', $off)['active'] === false)
            ->where('categories', fn ($categories): bool => $categories->firstWhere('id', $closed)['active'] === false)
        );
        $browser->get("/admin/products?brand={$off}")->assertInertia(fn (AssertableInertia $page) => $page->has('products', 1)->where('products.0.id', $draft)->where('brandId', $off));
        $browser->get("/admin/products/{$draft}")->assertInertia(fn (AssertableInertia $page) => $page
            ->where('brands', fn ($brands): bool => $brands->firstWhere('id', $off)['active'] === false)
            ->where('warranties', fn ($warranties): bool => $warranties->isNotEmpty() && $warranties->every(fn (array $warranty): bool => $warranty['active'] === true))
        );
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
                ->component('Catalog/Admin/Products/DetailsTab')
                ->where('product.id', $draft)
                ->where('tab', 'details')
                ->where('missing', ['description_ar', 'description_en', 'category', 'variants', 'photos'])
                ->has('brands')
                ->where('variants', null)
            );

        // Each tab is a page of its own, the product above it.
        $browser->get("/admin/products/{$draft}?tab=variants")->assertInertia(fn (AssertableInertia $page) => $page->component('Catalog/Admin/Products/VariantsTab')->where('tab', 'variants')->where('variants', [])->has('attributes')->where('brands', null));
        $browser->get("/admin/products/{$draft}?tab=photos")->assertInertia(fn (AssertableInertia $page) => $page->component('Catalog/Admin/Products/PhotosTab')->where('tab', 'photos'));
        $browser->get("/admin/products/{$draft}?tab=search")->assertInertia(fn (AssertableInertia $page) => $page->component('Catalog/Admin/Products/SearchTab')->where('searchWords', [])->where('filterValueIds', []));
        $browser->get("/admin/products/{$draft}?tab=related")->assertInertia(fn (AssertableInertia $page) => $page->component('Catalog/Admin/Products/RelatedTab')->where('related', [])->where('found', null));
        $browser->get("/admin/products/{$draft}?tab=nonsense")->assertInertia(fn (AssertableInertia $page) => $page->component('Catalog/Admin/Products/DetailsTab')->where('tab', 'details'));
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
        // Typed on an Arabic keyboard, the search reads 0-9; bytes that are not text find nothing and never fail.
        $browser->get("/admin/products/{$product}?tab=related&find=".urlencode(catalogProductScreensIndic($code)))->assertInertia(fn (AssertableInertia $page) => $page->has('found', 1)->where('found.0.id', $other));
        $browser->get("/admin/products/{$product}?tab=related&find=%FF")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('found', []));
        $browser->get("/admin/products/{$product}?tab=related")->assertInertia(fn (AssertableInertia $page) => $page->where('found', null));
    });

    it('shows a product\'s codes as its variants carry them now; a code it gave up still finds it', function () {
        ['product' => $product, 'variants' => [$variant]] = Px::ready();
        $old = (string) DB::table('catalog.variants')->where('id', $variant)->value('code');
        $browser = catalogProductScreens([P::PRODUCT_VIEW, P::VARIANT_CORRECT_CODE]);

        expect(catalogProductScreensToast($browser->post("/admin/products/{$product}/variants/{$variant}/code", ['code' => '9001'])))->toBe('Code corrected');

        $browser->get("/admin/products/{$product}")->assertInertia(fn (AssertableInertia $page) => $page->where('product.codes', ['9001']));
        $browser->get("/admin/products?q={$old}")->assertInertia(fn (AssertableInertia $page) => $page->has('products', 1)->where('products.0.codes', ['9001']));
    });

    it('says what the reader may do: making ready a draft with that job only, correcting a code a ready product\'s only', function () {
        $draft = Px::product('Hinge');
        ['product' => $ready] = Px::ready();
        $updater = catalogProductScreens([P::PRODUCT_VIEW, P::PRODUCT_UPDATE, P::VARIANT_CORRECT_CODE]);

        $updater->get("/admin/products/{$draft}")->assertInertia(fn (AssertableInertia $page) => $page->where('mayUpdate', true)->where('mayPublish', false)->where('mayArchive', false)->where('mayCorrectCode', false));
        $updater->get("/admin/products/{$ready}")->assertInertia(fn (AssertableInertia $page) => $page->where('mayPublish', false)->where('mayCorrectCode', true));
        catalogProductScreens([P::PRODUCT_VIEW, P::PRODUCT_PUBLISH])->get("/admin/products/{$draft}")
            ->assertInertia(fn (AssertableInertia $page) => $page->where('mayPublish', true)->where('mayUpdate', false));
    });

    it('opens an archived product, and the list narrows to the archived', function () {
        ['product' => $product] = Px::ready();
        Px::product('Hinge');
        expect(catalogProductScreensToast(catalogProductScreens([P::PRODUCT_VIEW, P::PRODUCT_ARCHIVE])->post("/admin/products/{$product}/archive")))->toBe('Product archived');
        $browser = catalogProductScreens([P::PRODUCT_VIEW]);

        $browser->get("/admin/products/{$product}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('product.stage', 'ARCHIVED')->where('product.archivedFrom', 'READY'));
        $browser->get('/admin/products?stage=ARCHIVED')->assertInertia(fn (AssertableInertia $page) => $page->has('products', 1)->where('products.0.id', $product)->where('stage', 'ARCHIVED'));
    });

    it('is read only to someone without the job in every store where it is on', function () {
        ['product' => $product] = Px::ready();
        catalogProductScreensOn('eg', $product);
        $browser = catalogProductScreens([P::PRODUCT_VIEW, P::PRODUCT_UPDATE], ['sa']);

        $browser->get("/admin/products/{$product}")->assertInertia(fn (AssertableInertia $page) => $page->where('mayUpdate', false));
        $name = DB::table('catalog.products')->where('id', $product)->value('name_ar');
        $media = DB::table('platform.media')->count();

        expect(AdminBrowser::formError($browser->post("/admin/products/{$product}/search-words", ['words' => ['rail']])))->toBe(catalogProductScreensNotYours())
            ->and(DB::table('catalog.product_search_words')->where('product_id', $product)->count())->toBe(0)
            ->and(AdminBrowser::formError($browser->post("/admin/products/{$product}/details", ['name_ar' => 'اسم آخر'])))->toBe(catalogProductScreensNotYours())
            ->and(DB::table('catalog.products')->where('id', $product)->value('name_ar'))->toBe($name)
            ->and(AdminBrowser::formError($browser->post("/admin/products/{$product}/variants", ['code' => '7001'])))->toBe(catalogProductScreensNotYours())
            ->and(AdminBrowser::formError($browser->post("/admin/products/{$product}/related/related", ['product_ids' => []])))->toBe(catalogProductScreensNotYours())
            // A photo is not even uploaded.
            ->and(AdminBrowser::formError($browser->post("/admin/products/{$product}/gallery", ['photos' => [UploadedFile::fake()->image('a.jpg', 300, 300)]])))->toBe(catalogProductScreensNotYours())
            ->and(DB::table('platform.media')->count())->toBe($media);
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

    it('gives a variant its own photos, never one of another product\'s variants', function () {
        ['product' => $product, 'variants' => [$variant]] = Px::ready();
        ['variants' => [$elsewhere]] = Px::ready();
        $browser = catalogProductScreens([P::PRODUCT_VIEW, P::PRODUCT_UPDATE]);

        expect(catalogProductScreensToast($browser->post("/admin/products/{$product}/variants/{$variant}/photos", ['photos' => [UploadedFile::fake()->image('v.jpg', 300, 300)]])))->toBe('Variant photos saved')
            ->and(DB::table('catalog.variant_photos')->where('variant_id', $variant)->count())->toBe(1);

        $media = DB::table('platform.media')->count();

        expect(AdminBrowser::formError($browser->post("/admin/products/{$product}/variants/{$elsewhere}/photos", ['photos' => [UploadedFile::fake()->image('w.jpg', 300, 300)]])))
            ->toBe(trans('catalog::errors.variant_not_found.detail', [], 'en'))
            ->and(DB::table('platform.media')->count())->toBe($media)
            ->and(DB::table('catalog.variant_photos')->where('variant_id', $elsewhere)->count())->toBe(0);
    });

    it('uploads nothing for someone holding the job in only one of the stores where the product is on', function (string $held) {
        ['product' => $product] = Px::ready();
        catalogProductScreensOn('sa', $product);
        catalogProductScreensOn('eg', $product);
        $browser = catalogProductScreens([P::PRODUCT_VIEW, P::PRODUCT_UPDATE], [$held]);
        $media = DB::table('platform.media')->count();

        // Platform's upload checks the job in one of those stores; the product's own check asks them all.
        expect(AdminBrowser::formError($browser->post("/admin/products/{$product}/gallery", ['photos' => [UploadedFile::fake()->image('a.jpg', 300, 300)]])))->toBe(catalogProductScreensNotYours())
            ->and(DB::table('platform.media')->count())->toBe($media);
    })->with(['sa', 'eg']);

    it('uploads a photo for whoever holds the job where the product is on, or - on nowhere - in some store', function () {
        ['product' => $product] = Px::ready();
        catalogProductScreensOn('eg', $product);
        $draft = Px::product('Hinge');
        $kept = DB::table('catalog.product_photos')->where('product_id', $product)->pluck('media_id')->all();

        expect(catalogProductScreensToast(catalogProductScreens([P::PRODUCT_VIEW, P::PRODUCT_UPDATE], ['eg'])->post("/admin/products/{$product}/gallery", ['media_ids' => $kept, 'photos' => [UploadedFile::fake()->image('a.jpg', 300, 300)]])))->toBe('Photos saved')
            ->and(DB::table('catalog.product_photos')->where('product_id', $product)->count())->toBe(2)
            ->and(catalogProductScreensToast(catalogProductScreens([P::PRODUCT_VIEW, P::PRODUCT_UPDATE], ['sa'])->post("/admin/products/{$draft}/gallery", ['photos' => [UploadedFile::fake()->image('b.jpg', 300, 300)]])))->toBe('Photos saved')
            ->and(DB::table('catalog.product_photos')->where('product_id', $draft)->count())->toBe(1);
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

        $browser->post("/admin/products/{$product}/related/everything", ['product_ids' => [$other]])->assertNotFound();
        $browser->get("/admin/products/{$product}?tab=search")->assertInertia(fn (AssertableInertia $page) => $page->where('searchWords', ['hinge', 'مفصلة'])->where('filterValueIds', [$matte]));
        $browser->get("/admin/products/{$product}?tab=related")->assertInertia(fn (AssertableInertia $page) => $page->where('related.0.productId', $other)->where('related.0.kind', 'GOES_WITH'));
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
        ['product' => $product, 'width' => $width] = Px::ready(['60 cm', '80 cm']);
        ['product' => $other] = Px::ready();
        catalogProductScreensOn('sa', $product);
        $browser = catalogProductScreens([P::PRODUCT_VIEW, P::PRODUCT_CREATE, P::PRODUCT_UPDATE, P::PRODUCT_PUBLISH, P::PRODUCT_ARCHIVE, P::VARIANT_CORRECT_CODE]);
        $uri = str_replace(['{product}', '{code}'], [$product, (string) DB::table('catalog.variants')->where('product_id', $other)->value('code')], $page);
        $own = catalogProductScreensOwnQueries($browser, $uri);

        expect($own)->toBe($recorded)->and($own)->toBeLessThanOrEqual(15);

        // More of everything the pages show: never one query a row.
        $finish = Px::attribute('Finish', 'FILTERABLE');
        $related = [];
        $filters = [];

        foreach (range(1, 5) as $n) {
            ['product' => $related[]] = Px::ready();
            catalogProductScreensOn('sa', $related[$n - 1]);
            Px::product("Draft {$n}");
            DB::table('catalog.product_photos')->insert(['product_id' => $product, 'media_id' => Cx::media(), 'position' => 10 + $n]);
            $variant = Px::variant($product, (string) (8000 + $n), [$width => Px::value($width, "{$n}5 cm")]);
            Fx::asSystem(fn () => app(SetVariantPhotosHandler::class)->handle(new SetVariantPhotos($variant, [Cx::media(), Cx::media()])));
            $filters[] = Px::value($finish, "Finish {$n}");
        }

        Fx::asSystem(function () use ($product, $related, $filters): void {
            app(SetRelationsHandler::class)->handle(new SetRelations($product, 'RELATED', $related));
            app(SetRelationsHandler::class)->handle(new SetRelations($product, 'GOES_WITH', array_reverse($related)));
            app(SetFilterValuesHandler::class)->handle(new SetFilterValues($product, $filters));
            app(SetSearchWordsHandler::class)->handle(new SetSearchWords($product, ['rail', 'hinge', 'مفصلة', 'drawer', 'soft']));
        });

        expect(catalogProductScreensOwnQueries($browser, $uri))->toBe($recorded);
    })->with([
        // Two of them ask Platform which stores are on: Add Product is offered only where it would be let in.
        'the list' => ['/admin/products', 15],
        'the list, one store' => ['/admin/products?store=sa&state=ON', 15],
        'details' => ['/admin/products/{product}', 12],
        'variants' => ['/admin/products/{product}?tab=variants', 13],
        'photos' => ['/admin/products/{product}?tab=photos', 11],
        'search and filters' => ['/admin/products/{product}?tab=search', 13],
        'related, finding' => ['/admin/products/{product}?tab=related&find={code}', 13],
        'related, finding many' => ['/admin/products/{product}?tab=related&find=drawer', 13],
    ]);
});
