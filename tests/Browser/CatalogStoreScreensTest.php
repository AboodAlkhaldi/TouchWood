<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Catalog\Application\CatalogPermissions as P;
use Modules\Catalog\Application\Command\AddAttribute\AddAttribute;
use Modules\Catalog\Application\Command\AddAttribute\AddAttributeHandler;
use Modules\Catalog\Application\Command\AddAttributeValue\AddAttributeValue;
use Modules\Catalog\Application\Command\AddAttributeValue\AddAttributeValueHandler;
use Modules\Catalog\Application\Command\AddBrand\AddBrand;
use Modules\Catalog\Application\Command\AddBrand\AddBrandHandler;
use Modules\Catalog\Application\Command\AddCategory\AddCategory;
use Modules\Catalog\Application\Command\AddCategory\AddCategoryHandler;
use Modules\Catalog\Application\Command\AddVariant\AddVariant;
use Modules\Catalog\Application\Command\AddVariant\AddVariantHandler;
use Modules\Catalog\Application\Command\AddVariantAttribute\AddVariantAttribute;
use Modules\Catalog\Application\Command\AddVariantAttribute\AddVariantAttributeHandler;
use Modules\Catalog\Application\Command\CreateProduct\CreateProduct;
use Modules\Catalog\Application\Command\CreateProduct\CreateProductHandler;
use Modules\Catalog\Application\Command\EditProductDetails\EditProductDetails;
use Modules\Catalog\Application\Command\EditProductDetails\EditProductDetailsHandler;
use Modules\Catalog\Application\Command\MarkProductReady\MarkProductReady;
use Modules\Catalog\Application\Command\MarkProductReady\MarkProductReadyHandler;
use Modules\Catalog\Application\Command\SetProductGallery\SetProductGallery;
use Modules\Catalog\Application\Command\SetProductGallery\SetProductGalleryHandler;
use Modules\Catalog\Application\Command\UploadStoreFill\UploadStoreFill;
use Modules\Catalog\Application\Command\UploadStoreFill\UploadStoreFillHandler;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Catalog\Support\CatalogFixtures as Cx;

use function Pest\Laravel\seed;

/*
| Catalog's store screens, in a real browser (catalog.md §4.4 S9 "Stores", S10).
|
| The feature tests beside them prove what each page is handed and what the server does with what it
| sends. These prove a person can use them: a ready product switched on in a store from its Stores
| tab, and off again; a store file's item ticked and switched on; both pages right to left in Arabic
| at a phone's width.
|
| No RefreshDatabase, for the reason written at the top of StaffScreensTest: every name and code here
| is made unique from the clock, as the rows stay. A file is not chosen here - the plugin's server
| receives no file (lesson 117); the store file is made through its use case, and the feature tests
| upload them.
*/

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

/**
 * Signed in through the panel's door with these jobs in every store.
 *
 * @param  list<string>  $permissions
 */
function catalogStoreBrowser(array $permissions, string $locale = 'en', RoleLevel $level = RoleLevel::Staff): mixed
{
    $staffId = Fx::staffWith($permissions, ['*'], $level);
    DB::table('access.staff_users')->where('id', $staffId)->update(['locale' => $locale]);
    $email = (string) DB::table('access.staff_users')->where('id', $staffId)->value('email');

    $page = visit('/admin/sign-in')
        ->type('#email', $email)
        ->type('#password', Fx::STAFF_PASSWORD)
        ->click('button[type="submit"]')
        ->assertPathIs('/admin/sign-in/code')
        ->type('input[autocomplete="one-time-code"]', RecordingSecurityMessages::installed()->lastCode())
        ->click('button[type="submit"]');

    expect(signedInToPanel($page))->toBeTrue();

    return $page;
}

/** What a read gives once a save has landed, waited through the page (CatalogListScreensTest). */
function catalogStoreBrowserSoon(mixed $page, Closure $read, Closure $holds): mixed
{
    $value = $read();

    for ($tries = 0; $tries < 50 && ! $holds($value); $tries++) {
        $page->wait(0.1);
        $value = $read();
    }

    return $value;
}

/** A number for names and codes that must be new, from the clock (CatalogListScreensTest). */
function catalogStoreBrowserFresh(): string
{
    return substr((string) hrtime(true), -12);
}

/**
 * A ready product of its own brand, category and attribute, with one variant whose code is the
 * clock's - the shared fixtures count from one, and these rows stay.
 *
 * @return array{product: string, variant: string, code: string}
 */
function catalogStoreBrowserReady(string $n): array
{
    return Fx::asSystem(function () use ($n): array {
        $brand = app(AddBrandHandler::class)->handle(new AddBrand("ماركة {$n}", "Brand {$n}", 'DISTRIBUTOR'));
        $category = app(AddCategoryHandler::class)->handle(new AddCategory("فئة {$n}", "Category {$n}"));
        $width = app(AddAttributeHandler::class)->handle(new AddAttribute("عرض {$n}", "Width {$n}", 'VARIANT'));
        $value = app(AddAttributeValueHandler::class)->handle(new AddAttributeValue($width, "ستون {$n}", "60 cm {$n}"));
        $product = app(CreateProductHandler::class)->handle(new CreateProduct("درج {$n}", "Drawer {$n}", $brand));
        app(AddVariantAttributeHandler::class)->handle(new AddVariantAttribute($product, $width, []));
        $text = ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Drawer']]]]];
        app(EditProductDetailsHandler::class)->handle(new EditProductDetails($product, "درج {$n}", "Drawer {$n}", $brand, descriptionAr: $text, descriptionEn: $text, categoryId: $category));
        $code = substr($n, -9);
        $variant = app(AddVariantHandler::class)->handle(new AddVariant($product, $code, [$width => $value]));
        app(SetProductGalleryHandler::class)->handle(new SetProductGallery($product, [Cx::media()]));
        app(MarkProductReadyHandler::class)->handle(new MarkProductReady($product));

        return ['product' => $product, 'variant' => $variant, 'code' => $code];
    });
}

it('switches a ready product on in a store from its Stores tab, and off again', function () {
    ['product' => $product, 'variant' => $variant] = catalogStoreBrowserReady(catalogStoreBrowserFresh());
    $sa = Fx::storeId('sa');
    $on = fn (): mixed => DB::table('catalog.store_variants')->where('store_id', $sa)->where('variant_id', $variant)->value('is_active');
    $page = catalogStoreBrowser([P::PRODUCT_VIEW, P::LISTING_CHOOSE, P::LISTING_SELLING]);
    $page->navigate("/admin/products/{$product}?tab=stores", BROWSER_PAGE_LOAD);

    $page->click('[data-test="switch-on-sa"]');

    expect(catalogStoreBrowserSoon($page, $on, fn ($found): bool => $found === true))->toBeTrue()
        ->and(browserUntil($page, "document.querySelector('[data-test=\"store-on-sa\"]')?.getAttribute('aria-checked') === 'true'"))->toBeTrue();

    $page->click('[data-test="store-on-sa"]');

    expect(catalogStoreBrowserSoon($page, $on, fn ($found): bool => $found === false))->toBeFalse();
    $page->assertNoJavaScriptErrors();
});

it('switches on a store file\'s ticked item from its page', function () {
    $n = catalogStoreBrowserFresh();
    ['variant' => $variant, 'code' => $code] = catalogStoreBrowserReady($n);
    $path = tempnam(sys_get_temp_dir(), 'fill');
    file_put_contents((string) $path, json_encode(['format' => 'touchwood-store-fill/1', 'items' => [['code' => $code, 'price' => 10], ['code' => '0'.substr($n, -9), 'price' => 5]]], JSON_THROW_ON_ERROR));
    $file = Fx::asSystem(fn (): string => app(UploadStoreFillHandler::class)->handle(new UploadStoreFill(Fx::storeId('sa'), (string) $path, "prices {$n}.json")));
    @unlink((string) $path);
    $page = catalogStoreBrowser([P::LISTING_FILL, P::PRODUCT_VIEW], level: RoleLevel::Admin);
    $page->navigate("/admin/store-files/{$file}", BROWSER_PAGE_LOAD);

    $page->click('[data-test="select-1"]')
        ->click('[data-test="switch-on-selected"]');

    $state = catalogStoreBrowserSoon($page, fn (): mixed => DB::table('catalog.store_fill_items')->where('import_id', $file)->where('number', 1)->value('state'), fn ($found): bool => $found === 'ON');

    expect($state)->toBe('ON')
        ->and(DB::table('catalog.store_variants')->where('store_id', Fx::storeId('sa'))->where('variant_id', $variant)->value('is_active'))->toBeTrue()
        ->and(browserUntil($page, "document.querySelector('[data-test=\"item-1\"]')?.getAttribute('data-state') === 'ON'"))->toBeTrue()
        ->and(browserUntil($page, "document.querySelector('[data-test=\"item-2\"] [data-test=\"standing\"]')?.innerText.includes('Unknown Code') === true"))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

it('reads right to left in Arabic, at a phone\'s width, with nothing wider than the screen', function () {
    $n = catalogStoreBrowserFresh();
    ['product' => $product, 'code' => $code] = catalogStoreBrowserReady($n);
    $path = tempnam(sys_get_temp_dir(), 'fill');
    file_put_contents((string) $path, json_encode(['format' => 'touchwood-store-fill/1', 'items' => [['code' => $code, 'price' => 10]]], JSON_THROW_ON_ERROR));
    $file = Fx::asSystem(fn (): string => app(UploadStoreFillHandler::class)->handle(new UploadStoreFill(Fx::storeId('sa'), (string) $path, "prices {$n}.json")));
    @unlink((string) $path);
    $page = catalogStoreBrowser([P::PRODUCT_VIEW, P::LISTING_CHOOSE, P::LISTING_FILL], 'ar', RoleLevel::Admin);
    $page->resize(375, 812);

    foreach (["/admin/products/{$product}?tab=stores" => 'store-sa', "/admin/store-files/{$file}" => 'item-1', '/admin/store-files?store=sa' => "store-file-{$file}"] as $address => $shown) {
        $page->navigate($address, BROWSER_PAGE_LOAD);

        expect(browserUntil($page, "document.querySelector('[data-test=\"{$shown}\"]') !== null"))->toBeTrue()
            ->and($page->script('document.documentElement.dir'))->toBe('rtl')
            ->and($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
    }

    $page->assertNoJavaScriptErrors();
});
