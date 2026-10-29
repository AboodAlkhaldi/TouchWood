<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Catalog\Application\CatalogPermissions;
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
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Catalog\Application\Command\CreateProduct\CreateProduct;
use Modules\Catalog\Application\Command\CreateProduct\CreateProductHandler;
use Modules\Catalog\Application\Command\EditProductDetails\EditProductDetails;
use Modules\Catalog\Application\Command\EditProductDetails\EditProductDetailsHandler;
use Modules\Catalog\Application\Command\MarkProductReady\MarkProductReady;
use Modules\Catalog\Application\Command\MarkProductReady\MarkProductReadyHandler;
use Modules\Catalog\Application\Command\SetProductGallery\SetProductGallery;
use Modules\Catalog\Application\Command\SetProductGallery\SetProductGalleryHandler;
use Modules\Inventory\Application\Command\SetStock\SetStock;
use Modules\Inventory\Application\Command\SetStock\SetStockHandler;
use Modules\Inventory\Application\InventoryPermissions as P;
use Modules\Inventory\Public\Contracts\InventoryApi;
use Modules\Inventory\Public\Dto\HoldLineDto;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Catalog\Support\CatalogFixtures as Cx;

use function Pest\Laravel\seed;

/*
| Inventory's screens in a real browser (inventory.md §4.3, stage 5's screens step 5). The feature tests beside
| them prove what each page is handed and what the server does with what it sends; these prove a person can
| use them: a count typed in its row and saved from the save bar, the amber toast when orders are left short
| (I1); Add or Remove Stock; Set Threshold for the selected rows; the size's side panel with its History; Low
| Stock (I2); the product page's Inventory section in two store cards, each with boxes of its own; and Arabic
| right to left.
|
| No RefreshDatabase, as the other browser tests: every product, code and attribute here is the clock's own,
| as the rows stay - so each page is opened searched for them.
*/

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
    inventoryBrowserNoScreenshots();
});

afterEach(function () {
    inventoryBrowserNoScreenshots();
});

/** A failed run leaves no screenshots behind. */
function inventoryBrowserNoScreenshots(): void
{
    $folder = dirname(__DIR__).'/Browser/Screenshots';

    if (is_dir($folder)) {
        foreach (glob($folder.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($folder);
    }
}

/**
 * Signed in through the panel's door, managing stock and seeing products in every store.
 */
function inventoryBrowser(string $locale = 'en'): mixed
{
    $staffId = Fx::staffWith([P::STOCK_MANAGE, CatalogPermissions::PRODUCT_VIEW], ['*']);
    DB::table('access.staff_users')->where('id', $staffId)->update(['locale' => $locale]);
    $email = (string) DB::table('access.staff_users')->where('id', $staffId)->value('email');

    $page = visit('/admin/sign-in')
        ->type('#email', $email)
        ->type('#password', Fx::STAFF_PASSWORD)
        ->click('button[type="submit"]');

    // A busy machine answers slowly: each step waits for the page it leads to (tests/Pest.php, browserSoon).
    expect(browserSoon($page, "window.location.pathname === '/admin/sign-in/code' && document.querySelector('input[autocomplete=\"one-time-code\"]') !== null"))->toBeTrue();

    $page->type('input[autocomplete="one-time-code"]', RecordingSecurityMessages::installed()->lastCode())
        ->click('button[type="submit"]');

    expect(browserSoon($page, "window.location.pathname === '/admin'"))->toBeTrue();

    return $page;
}

/** A read of the database once a save has landed, waited for through the page. */
function inventoryBrowserSoon(mixed $page, Closure $read, Closure $holds): mixed
{
    $value = $read();

    for ($tries = 0; $tries < 150 && ! $holds($value); $tries++) {
        $page->wait(0.1);
        $value = $read();
    }

    return $value;
}

/**
 * A ready product of its own brand, category and attribute, with two sizes switched on in these stores. The
 * two codes are the clock's last eight digits behind 4 and behind 5: they never meet each other, and never a
 * code of another run.
 *
 * @param  list<string>  $stores
 * @return array{product: string, sizes: list<string>, codes: list<string>, name: string, values: list<string>}
 */
function inventoryBrowserProduct(array $stores = ['sa']): array
{
    $n = substr((string) hrtime(true), -12);
    $ready = Fx::asSystem(function () use ($n): array {
        $brand = app(AddBrandHandler::class)->handle(new AddBrand("ماركة {$n}", "Brand {$n}", 'DISTRIBUTOR'));
        $category = app(AddCategoryHandler::class)->handle(new AddCategory("فئة {$n}", "Category {$n}"));
        $width = app(AddAttributeHandler::class)->handle(new AddAttribute("عرض {$n}", "Width {$n}", 'VARIANT'));
        $names = ["60 cm {$n}", "80 cm {$n}"];
        $values = [
            app(AddAttributeValueHandler::class)->handle(new AddAttributeValue($width, "ستون {$n}", $names[0])),
            app(AddAttributeValueHandler::class)->handle(new AddAttributeValue($width, "ثمانون {$n}", $names[1])),
        ];
        $product = app(CreateProductHandler::class)->handle(new CreateProduct("رف {$n}", "Shelf {$n}", $brand));
        app(AddVariantAttributeHandler::class)->handle(new AddVariantAttribute($product, $width, []));
        $text = ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Shelf']]]]];
        app(EditProductDetailsHandler::class)->handle(new EditProductDetails($product, "رف {$n}", "Shelf {$n}", $brand, descriptionAr: $text, descriptionEn: $text, categoryId: $category));
        $tail = substr($n, -8);
        $codes = ["4{$tail}", "5{$tail}"];
        $sizes = [
            app(AddVariantHandler::class)->handle(new AddVariant($product, $codes[0], [$width => $values[0]])),
            app(AddVariantHandler::class)->handle(new AddVariant($product, $codes[1], [$width => $values[1]])),
        ];
        app(SetProductGalleryHandler::class)->handle(new SetProductGallery($product, [Cx::media()]));
        app(MarkProductReadyHandler::class)->handle(new MarkProductReady($product));

        return ['product' => $product, 'sizes' => $sizes, 'codes' => $codes, 'name' => "Shelf {$n}", 'values' => $names];
    });

    foreach ($stores as $store) {
        Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId($store), $ready['product'], true)));
    }

    return $ready;
}

/**
 * @param  array<string, int>  $counts
 */
function inventoryBrowserCount(string $store, array $counts): void
{
    Fx::asSystem(fn () => app(SetStockHandler::class)->handle(new SetStock(Fx::storeId($store), $counts)));
}

function inventoryBrowserInStock(string $store, string $size): ?int
{
    $count = DB::table('inventory.stock')->where('store_id', Fx::storeId($store))->where('variant_id', $size)->value('in_stock');

    return $count === null ? null : (int) $count;
}

function inventoryBrowserThreshold(string $store, string $size): ?int
{
    $threshold = DB::table('inventory.stock')->where('store_id', Fx::storeId($store))->where('variant_id', $size)->value('threshold');

    return $threshold === null ? null : (int) $threshold;
}

it('counts in the row and saves from the save bar, saying in amber when orders are left short', function () {
    $ready = inventoryBrowserProduct();
    [$held, $other] = $ready['sizes'];
    inventoryBrowserCount('sa', [$held => 6, $other => 20]);
    app(InventoryApi::class)->hold(StoreId::fromString(Fx::storeId('sa')), strtolower((string) Str::ulid()), [new HoldLineDto($held, 5)], null);
    $page = inventoryBrowser();
    $page->navigate("/admin/stock?q={$ready['codes'][0]}", BROWSER_SLOW_PAGE_LOAD);

    expect(browserSoon($page, "document.getElementById('count-{$held}')?.value === '6'"))->toBeTrue();

    $page->type("#count-{$held}", '2');

    // Said under the box before it is saved, and the bar counts the one change.
    expect(browserSoon($page, "document.getElementById('count-{$held}-warning') !== null"))->toBeTrue()
        ->and(browserSoon($page, "document.getElementById('count-save-bar-message')?.textContent.includes('1')"))->toBeTrue();

    $page->click('[data-test="save-bar-save"]');

    expect(inventoryBrowserSoon($page, fn (): ?int => inventoryBrowserInStock('sa', $held), fn (?int $count): bool => $count === 2))->toBe(2)
        ->and(browserSoon($page, "document.body.textContent.includes('Counts saved')"))->toBeTrue()
        ->and(browserSoon($page, "[...document.querySelectorAll('[data-sonner-toast][data-type=\"warning\"]')].some((toast) => toast.textContent.includes('1 order is short of {$ready['values'][0]}'))"))->toBeTrue();
});

it('says a save once: opening a drawer after it does not say it again', function () {
    $ready = inventoryBrowserProduct();
    [$size] = $ready['sizes'];
    inventoryBrowserCount('sa', [$size => 6]);
    $page = inventoryBrowser();
    $page->navigate("/admin/stock?q={$ready['codes'][0]}", BROWSER_SLOW_PAGE_LOAD);
    $saved = "[...document.querySelectorAll('[data-sonner-toast]')].filter((toast) => toast.textContent.includes('Counts saved')).length";

    expect(browserSoon($page, "document.getElementById('count-{$size}')?.value === '6'"))->toBeTrue();

    $page->type("#count-{$size}", '8')->click('[data-test="save-bar-save"]');

    expect(inventoryBrowserSoon($page, fn (): ?int => inventoryBrowserInStock('sa', $size), fn (?int $count): bool => $count === 8))->toBe(8)
        ->and(browserSoon($page, "{$saved} === 1"))->toBeTrue();

    // A partial reload - the drawer's - keeps the page's last words to itself.
    $page->click("[data-test=\"open-size-{$size}\"]");

    expect(browserSoon($page, "document.querySelector('[data-test=\"drawer-history\"] [data-test=\"history-line\"]') !== null"))->toBeTrue();

    $page->wait(0.5);

    expect($page->script("() => {$saved}"))->toBe(1);
});

it('adds stock through Add or Remove Stock', function () {
    $ready = inventoryBrowserProduct();
    [$size] = $ready['sizes'];
    inventoryBrowserCount('sa', [$size => 6]);
    $page = inventoryBrowser();
    $page->navigate("/admin/stock?q={$ready['codes'][0]}", BROWSER_SLOW_PAGE_LOAD);

    expect(browserSoon($page, "document.querySelector('[data-test=\"stock-actions-{$size}\"]') !== null"))->toBeTrue();

    $page->click("[data-test=\"stock-actions-{$size}\"]")->click('[data-test="adjust-stock"]');

    expect(browserSoon($page, "document.querySelector('[data-test=\"adjust-dialog\"]') !== null"))->toBeTrue();

    $page->click('[data-test="adjust-add"]')->type('#adjust-quantity', '4');

    expect(browserSoon($page, "document.getElementById('adjust-reason-RECEIVED') !== null"))->toBeTrue();

    $page->click('#adjust-reason-RECEIVED');

    expect(browserSoon($page, "document.querySelector('[data-test=\"adjust-becomes\"]')?.textContent.includes('10')"))->toBeTrue();

    $page->click('[data-test="confirm-adjust"]');

    expect(inventoryBrowserSoon($page, fn (): ?int => inventoryBrowserInStock('sa', $size), fn (?int $count): bool => $count === 10))->toBe(10)
        ->and(browserSoon($page, "document.body.textContent.includes('Stock added')"))->toBeTrue();
});

it('sets one threshold for the selected rows', function () {
    $ready = inventoryBrowserProduct();
    [$first, $second] = $ready['sizes'];
    inventoryBrowserCount('sa', [$first => 30, $second => 30]);
    $page = inventoryBrowser();
    $page->navigate("/admin/stock?q={$ready['name']}", BROWSER_SLOW_PAGE_LOAD);

    expect(browserSoon($page, "document.querySelector('[data-test=\"select-{$first}\"]') !== null && document.querySelector('[data-test=\"select-{$second}\"]') !== null"))->toBeTrue();

    $page->click("[data-test=\"select-{$first}\"]")->click("[data-test=\"select-{$second}\"]");

    expect(browserSoon($page, "document.querySelector('[data-test=\"selection\"]')?.textContent.includes('2')"))->toBeTrue();

    $page->click('[data-test="threshold-selected"]');

    expect(browserSoon($page, "document.getElementById('threshold-value') !== null"))->toBeTrue();

    $page->type('#threshold-value', '3')->click('[data-test="confirm-threshold"]');

    $both = fn (): array => [inventoryBrowserThreshold('sa', $first), inventoryBrowserThreshold('sa', $second)];

    expect(inventoryBrowserSoon($page, $both, fn (array $thresholds): bool => $thresholds === [3, 3]))->toBe([3, 3])
        ->and(browserSoon($page, "document.body.textContent.includes('Thresholds saved')"))->toBeTrue();
});

it('opens a size\'s side panel from its row, with its History, and closes it with Escape', function () {
    $ready = inventoryBrowserProduct();
    [$size] = $ready['sizes'];
    inventoryBrowserCount('sa', [$size => 6]);
    inventoryBrowserCount('sa', [$size => 8]);
    $page = inventoryBrowser();
    $page->navigate("/admin/stock?q={$ready['codes'][0]}", BROWSER_SLOW_PAGE_LOAD);

    expect(browserSoon($page, "document.querySelector('[data-test=\"open-size-{$size}\"]') !== null"))->toBeTrue();

    $page->click("[data-test=\"open-size-{$size}\"]");

    expect(browserSoon($page, "document.querySelector('[data-test=\"drawer-body\"]') !== null"))->toBeTrue()
        ->and(browserSoon($page, "new URL(window.location.href).searchParams.get('size') === '{$size}'"))->toBeTrue()
        ->and(browserSoon($page, "document.querySelectorAll('[data-test=\"drawer-history\"] [data-test=\"history-line\"]').length === 2"))->toBeTrue()
        ->and(browserSoon($page, "document.querySelector('[data-test=\"drawer-history\"]')?.textContent.includes('Stocktake')"))->toBeTrue();

    // A phone (frontend.md §2.1): the open panel fits the screen, with nothing scrolling sideways.
    $page->resize(375, 812);
    expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue('Stock scrolls sideways')
        ->and($page->script("document.querySelector('[data-test=\"drawer-body\"]').getBoundingClientRect().right <= window.innerWidth"))->toBeTrue('the side panel is wider than the screen');

    $page->keys('[data-test="drawer-adjust"]', 'Escape');

    expect(browserSoon($page, "document.querySelector('[data-test=\"drawer-body\"]') === null"))->toBeTrue();
});

it('lists a low size on Low Stock, which opens it on Stock', function () {
    $ready = inventoryBrowserProduct();
    [$low, $plenty] = $ready['sizes'];
    inventoryBrowserCount('sa', [$low => 1, $plenty => 50]);
    $page = inventoryBrowser();
    $page->navigate('/admin/low-stock', BROWSER_SLOW_PAGE_LOAD);

    // The rows other runs left are low too: Show More until this one is drawn.
    $found = false;

    for ($tries = 0; $tries < 10 && ! $found; $tries++) {
        $found = browserSoon($page, "document.querySelector('[data-test=\"low-row-{$low}\"]') !== null || (document.querySelector('[data-test=\"more\"]:not([disabled])')?.click(), false)");
    }

    expect($found)->toBeTrue()
        ->and(browserSoon($page, "document.querySelector('[data-test=\"low-row-{$plenty}\"]') === null"))->toBeTrue();

    $page->click("[data-test=\"open-size-{$low}\"]");

    expect(browserSoon($page, "window.location.pathname === '/admin/stock' && document.querySelector('[data-test=\"drawer-body\"]') !== null"))->toBeTrue();

    // A phone (frontend.md §2.1): Low Stock fits, its table scrolling inside its card.
    $page->resize(375, 812);
    $page->navigate('/admin/low-stock', BROWSER_SLOW_PAGE_LOAD);
    expect(browserSoon($page, "document.querySelector('[data-test^=\"low-row-\"]') !== null"))->toBeTrue()
        ->and($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
});

it('draws the product page\'s Inventory section in two store cards, each with boxes of its own', function () {
    $ready = inventoryBrowserProduct(['sa', 'eg']);
    [$size] = $ready['sizes'];
    inventoryBrowserCount('sa', [$size => 7]);
    inventoryBrowserCount('eg', [$size => 3]);
    $page = inventoryBrowser();
    $page->navigate("/admin/products/{$ready['product']}?tab=stores", BROWSER_SLOW_PAGE_LOAD);

    expect(browserSoon($page, "document.querySelector('[data-test=\"inventory-section-sa\"]') !== null && document.querySelector('[data-test=\"inventory-section-eg\"]') !== null"))->toBeTrue()
        ->and(browserSoon($page, "document.getElementById('count-sa-{$size}')?.value === '7' && document.getElementById('count-eg-{$size}')?.value === '3'"))->toBeTrue();

    $page->type("#count-eg-{$size}", '9');

    // Only the Egyptian card's bar: the Saudi card's boxes are untouched.
    expect(browserSoon($page, "document.getElementById('count-eg-save-bar-message') !== null && document.getElementById('count-sa-save-bar-message') === null"))->toBeTrue();

    $page->click('[data-test="inventory-section-eg"] [data-test="save-bar-save"]');

    expect(inventoryBrowserSoon($page, fn (): ?int => inventoryBrowserInStock('eg', $size), fn (?int $count): bool => $count === 9))->toBe(9)
        ->and(inventoryBrowserInStock('sa', $size))->toBe(7);
});

it('draws Stock right to left in Arabic', function () {
    $ready = inventoryBrowserProduct();
    inventoryBrowserCount('sa', [$ready['sizes'][0] => 4]);
    $page = inventoryBrowser('ar');
    $page->navigate("/admin/stock?q={$ready['codes'][0]}", BROWSER_SLOW_PAGE_LOAD);

    expect(browserSoon($page, "document.documentElement.dir === 'rtl'"))->toBeTrue()
        ->and(browserSoon($page, "document.querySelector('h1')?.textContent.includes('المخزون')"))->toBeTrue()
        ->and(browserSoon($page, "document.getElementById('count-{$ready['sizes'][0]}')?.value === '4'"))->toBeTrue();

    // A phone, right to left (frontend.md §2.1): nothing scrolls sideways.
    $page->resize(375, 812);
    expect($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
});
