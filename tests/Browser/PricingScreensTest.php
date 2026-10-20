<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
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
use Modules\Catalog\Application\Command\SetSellingTerms\SetSellingTerms;
use Modules\Catalog\Application\Command\SetSellingTerms\SetSellingTermsHandler;
use Modules\Pricing\Application\Command\AddSale\AddSale;
use Modules\Pricing\Application\Command\AddSale\AddSaleHandler;
use Modules\Pricing\Application\Command\SetRetailPrice\SetRetailPrice;
use Modules\Pricing\Application\Command\SetRetailPrice\SetRetailPriceHandler;
use Modules\Pricing\Application\Command\SetWholesalePrices\SetWholesalePrices;
use Modules\Pricing\Application\Command\SetWholesalePrices\SetWholesalePricesHandler;
use Modules\Pricing\Application\PricingPermissions as P;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;
use Tests\Modules\Catalog\Support\CatalogFixtures as Cx;

use function Pest\Laravel\seed;

/*
| Pricing's screens in a real browser (pricing.md §4.3, stage 5's screens step 3). The feature tests beside
| them prove what each page is handed and what the server does with what it sends; these prove a person can
| use them: a price typed, the save bar, a refusal marking its row, the drawer from the address, Arabic
| right to left (P1); an offer added through its dialog - product, sizes, date and time, the note - and ended
| (P2); a discount added with its live preview, and its page (P3); a band added and removed (P4); a size
| priced off Needs a Price (P5); and the product page's Pricing section in two store cards.
|
| No RefreshDatabase, as the other browser tests: every product, code and category here is the clock's
| own, as the rows stay - so each page is opened searched for them.
*/

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
    pricingBrowserNoScreenshots();
});

afterEach(function () {
    pricingBrowserNoScreenshots();
});

/** A failed run leaves no screenshots behind. */
function pricingBrowserNoScreenshots(): void
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
 * Signed in through the panel's door with Pricing's three jobs and the products' in every store.
 */
function pricingBrowser(string $locale = 'en'): mixed
{
    $staffId = Fx::staffWith([P::PRICE_EDIT, P::WHOLESALE_EDIT, P::CATEGORY_DISCOUNT_MANAGE, CatalogPermissions::PRODUCT_VIEW], ['*']);
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
function pricingBrowserSoon(mixed $page, Closure $read, Closure $holds): mixed
{
    $value = $read();

    for ($tries = 0; $tries < 150 && ! $holds($value); $tries++) {
        $page->wait(0.1);
        $value = $read();
    }

    return $value;
}

/**
 * A ready product of its own brand, category and attribute, with two sizes whose codes are the clock's,
 * switched on in these stores.
 *
 * @param  list<string>  $stores
 * @return array{product: string, sizes: list<string>, codes: list<string>, category: string, name: string}
 */
function pricingBrowserProduct(array $stores = ['sa']): array
{
    $n = substr((string) hrtime(true), -12);
    $ready = Fx::asSystem(function () use ($n): array {
        $brand = app(AddBrandHandler::class)->handle(new AddBrand("ماركة {$n}", "Brand {$n}", 'DISTRIBUTOR'));
        $category = app(AddCategoryHandler::class)->handle(new AddCategory("فئة {$n}", "Category {$n}"));
        $width = app(AddAttributeHandler::class)->handle(new AddAttribute("عرض {$n}", "Width {$n}", 'VARIANT'));
        $values = [
            app(AddAttributeValueHandler::class)->handle(new AddAttributeValue($width, "ستون {$n}", "60 cm {$n}")),
            app(AddAttributeValueHandler::class)->handle(new AddAttributeValue($width, "ثمانون {$n}", "80 cm {$n}")),
        ];
        $product = app(CreateProductHandler::class)->handle(new CreateProduct("درج {$n}", "Drawer {$n}", $brand));
        app(AddVariantAttributeHandler::class)->handle(new AddVariantAttribute($product, $width, []));
        $text = ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Drawer']]]]];
        app(EditProductDetailsHandler::class)->handle(new EditProductDetails($product, "درج {$n}", "Drawer {$n}", $brand, descriptionAr: $text, descriptionEn: $text, categoryId: $category));
        // The second size's code differs in its last digit - never the same as the first (a first ending in 7 got 7 again).
        $codes = [substr($n, -9), substr($n, -9, 8).(str_ends_with($n, '7') ? '8' : '7')];
        $sizes = [
            app(AddVariantHandler::class)->handle(new AddVariant($product, $codes[0], [$width => $values[0]])),
            app(AddVariantHandler::class)->handle(new AddVariant($product, $codes[1], [$width => $values[1]])),
        ];
        app(SetProductGalleryHandler::class)->handle(new SetProductGallery($product, [Cx::media()]));
        app(MarkProductReadyHandler::class)->handle(new MarkProductReady($product));

        return ['product' => $product, 'sizes' => $sizes, 'codes' => $codes, 'category' => $category, 'name' => "Drawer {$n}"];
    });

    foreach ($stores as $store) {
        Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId($store), $ready['product'], true)));
    }

    return $ready;
}

/**
 * @param  array<string, string>  $prices
 */
function pricingBrowserPrice(string $store, array $prices): void
{
    Fx::asSystem(fn () => app(SetRetailPriceHandler::class)->handle(new SetRetailPrice(Fx::storeId($store), $prices)));
}

function pricingBrowserRetail(string $store, string $size): ?int
{
    $minor = DB::table('pricing.retail_prices')->where('store_id', Fx::storeId($store))->where('variant_id', $size)->value('amount_minor');

    return $minor === null ? null : (int) $minor;
}

it('types a price, saves it from the save bar, marks a refused row, and opens a size\'s drawer from its address', function () {
    $ready = pricingBrowserProduct();
    [$priced, $unpriced] = $ready['sizes'];
    pricingBrowserPrice('sa', [$priced => '120.00']);
    // An offer at a price of its own: a retail price of 300.00 would take it past the ceiling, refused naming the size.
    Fx::asSystem(fn () => app(AddSaleHandler::class)->handle(new AddSale(Fx::storeId('sa'), null, [$priced], null, '100.00', null, null)));
    $page = pricingBrowser();
    $page->navigate("/admin/prices?q={$ready['codes'][0]}", BROWSER_SLOW_PAGE_LOAD);

    expect(browserSoon($page, "document.getElementById('price-{$priced}') !== null"))->toBeTrue();

    $page->type("#price-{$priced}", '300')->click('[data-test="save-bar-save"]');

    expect(browserSoon($page, "document.getElementById('price-{$priced}-error') !== null"))->toBeTrue()
        ->and(pricingBrowserRetail('sa', $priced))->toBe(12000);

    $page->click('[data-test="save-bar-cancel"]');
    $page->navigate("/admin/prices?q={$ready['name']}&filter=no_price", BROWSER_SLOW_PAGE_LOAD);

    expect(browserSoon($page, "document.getElementById('price-{$unpriced}') !== null"))->toBeTrue();

    $page->type("#price-{$unpriced}", '149.50');

    expect(browserSoon($page, "document.querySelector('[data-test=\"save-bar\"]')?.textContent.includes('1 price changed')"))->toBeTrue();

    $page->click('[data-test="save-bar-save"]');

    expect(pricingBrowserSoon($page, fn (): ?int => pricingBrowserRetail('sa', $unpriced), fn (?int $minor): bool => $minor === 14950))->toBe(14950)
        ->and(browserSoon($page, "document.body.textContent.includes('Price saved')"))->toBeTrue();

    $page->navigate("/admin/prices?q={$ready['codes'][0]}&size={$priced}", BROWSER_SLOW_PAGE_LOAD);

    expect(browserSoon($page, "document.querySelector('[data-test=\"drawer-body\"]') !== null"))->toBeTrue()
        ->and(browserSoon($page, "document.querySelector('[data-test=\"size-drawer\"]')?.textContent.includes('What Reaches It')"))->toBeTrue();
});

it('draws Prices right to left in Arabic', function () {
    $ready = pricingBrowserProduct();
    pricingBrowserPrice('sa', [$ready['sizes'][0] => '120.00']);
    $page = pricingBrowser('ar');
    $page->navigate("/admin/prices?q={$ready['codes'][0]}", BROWSER_SLOW_PAGE_LOAD);

    expect(browserSoon($page, "document.documentElement.dir === 'rtl'"))->toBeTrue()
        ->and(browserSoon($page, "document.body.textContent.includes('سعر التجزئة') && document.body.textContent.includes('مُدخل هنا')"))->toBeTrue();
});

it('adds an offer through its dialog - product, sizes, date and time, the note - and ends it', function () {
    $ready = pricingBrowserProduct();
    pricingBrowserPrice('sa', [$ready['sizes'][0] => '120.00', $ready['sizes'][1] => '130.00']);
    $page = pricingBrowser();
    $page->navigate('/admin/offers', BROWSER_SLOW_PAGE_LOAD);

    $page->click('[data-test="add-offer"]')
        ->type('[data-test="offer-product-search"]', $ready['name']);

    expect(browserSoon($page, "document.querySelector('[data-test=\"offer-product-{$ready['product']}\"]') !== null"))->toBeTrue();

    $page->click("[data-test=\"offer-product-{$ready['product']}\"]");

    expect(browserSoon($page, "document.querySelector('[data-test=\"offer-all-sizes\"]') !== null"))->toBeTrue();

    $page->click('[data-test="offer-kind-percent"]')
        ->type('#offer-amount', '10')
        ->click('[data-test="offer-ends-none"]')
        ->click('[data-test="offer-ends-date"]');

    expect(browserSoon($page, "document.querySelectorAll('[data-slot=\"calendar\"] button[data-day]:not([disabled])').length > 0"))->toBeTrue();

    // The last day the calendar offers, then a time in the store's clock.
    $page->script("() => { const days = document.querySelectorAll('[data-slot=\"calendar\"] button[data-day]:not([disabled])'); days[days.length - 1].click(); }");
    $page->type('#offer-ends-time', '23:59');

    expect(browserSoon($page, "document.querySelector('[data-test=\"offer-note\"]')?.textContent.includes('Wins today')"))->toBeTrue();

    $page->click('[data-test="confirm-offer"]');

    $sale = pricingBrowserSoon($page, fn (): mixed => DB::table('pricing.sales')->where('product_id', $ready['product'])->first(), fn (mixed $row): bool => $row !== null);

    expect($sale)->not->toBeNull()
        ->and($sale->ends_at)->not->toBeNull()
        ->and((int) $sale->percent_bp)->toBe(1000)
        ->and(browserSoon($page, "document.body.textContent.includes('Offer added')"))->toBeTrue();

    // Running from the second after it was added.
    $page->wait(1.5);
    $page->navigate("/admin/offers?product={$ready['product']}", BROWSER_SLOW_PAGE_LOAD);
    $page->click("[data-test=\"offer-actions-{$sale->id}\"]")
        ->click('[data-test="end-offer"]')
        ->click('[data-test="confirm-end-offer"]');

    expect(pricingBrowserSoon($page, fn (): bool => DB::table('pricing.sales')->where('id', $sale->id)->where('ends_at', '<=', now())->exists(), fn (bool $ended): bool => $ended))->toBeTrue()
        ->and(browserSoon($page, "document.body.textContent.includes('Offer ended')"))->toBeTrue()
        // The dialog closed, focus is back on the ended offer's ⋯ - its row stays - never the page's start.
        ->and(browserSoon($page, "document.querySelector('[role=\"alertdialog\"]') === null && document.activeElement?.getAttribute('data-test') === 'offer-actions-{$sale->id}'"))->toBeTrue();
});

it('removes a scheduled offer, focus going to the next offer\'s menu, else to Add Offer', function () {
    $ready = pricingBrowserProduct();
    pricingBrowserPrice('sa', [$ready['sizes'][0] => '120.00', $ready['sizes'][1] => '130.00']);
    $later = now()->addDays(3)->utc()->format('Y-m-d H:i:s').'+00';
    $sales = Fx::asSystem(fn (): array => array_map(
        fn (string $size): string => app(AddSaleHandler::class)->handle(new AddSale(Fx::storeId('sa'), null, [$size], '10', null, $later, null)),
        $ready['sizes'],
    ));
    $page = pricingBrowser();
    $page->navigate("/admin/offers?product={$ready['product']}", BROWSER_SLOW_PAGE_LOAD);

    expect(browserSoon($page, "document.querySelectorAll('[data-test^=\"offer-actions-\"]').length === 2"))->toBeTrue();

    // The first row's offer: its row leaves, so focus goes to the other row's ⋯.
    $first = (string) $page->script("() => document.querySelector('[data-test^=\"offer-actions-\"]').getAttribute('data-test').slice('offer-actions-'.length)");
    $second = $first === $sales[0] ? $sales[1] : $sales[0];
    $page->click("[data-test=\"offer-actions-{$first}\"]")
        ->click('[data-test="remove-offer"]')
        ->click('[data-test="confirm-remove-offer"]');

    expect(browserSoon($page, "document.body.textContent.includes('Offer removed') && document.querySelector('[data-test=\"offer-actions-{$first}\"]') === null"))->toBeTrue()
        ->and(browserSoon($page, "document.querySelector('[role=\"alertdialog\"]') === null && document.activeElement?.getAttribute('data-test') === 'offer-actions-{$second}'"))->toBeTrue();

    // The last one: no offer is left, so focus goes to Add Offer.
    $page->click("[data-test=\"offer-actions-{$second}\"]")
        ->click('[data-test="remove-offer"]')
        ->click('[data-test="confirm-remove-offer"]');

    expect(browserSoon($page, "document.querySelector('[data-test^=\"offer-actions-\"]') === null && document.querySelector('[role=\"alertdialog\"]') === null && document.activeElement?.getAttribute('data-test') === 'add-offer'"))->toBeTrue()
        ->and(DB::table('pricing.sales')->whereIn('id', $sales)->count())->toBe(0);
});

it('adds a category discount with its live preview, and opens the discount\'s page', function () {
    $ready = pricingBrowserProduct();
    pricingBrowserPrice('sa', [$ready['sizes'][0] => '120.00', $ready['sizes'][1] => '130.00']);
    $page = pricingBrowser();
    $page->navigate('/admin/category-discounts', BROWSER_SLOW_PAGE_LOAD);

    $page->click('[data-test="add-discount"]')
        ->click('[data-test="discount-category"]');

    expect(browserSoon($page, "document.querySelector('[cmdk-item][data-value=\"option-{$ready['category']}\"]') !== null"))->toBeTrue();

    $page->click("[cmdk-item][data-value=\"option-{$ready['category']}\"]")
        ->click('[data-test="discount-kind-percent"]')
        ->type('#discount-amount', '10')
        ->click('[data-test="discount-lines-BOTH"]');

    expect(browserSoon($page, "document.querySelector('[data-test=\"preview-reaches\"]')?.textContent.includes('Reaches 2 sizes in 1 product')"))->toBeTrue();

    $page->click('[data-test="confirm-discount"]');

    $discount = pricingBrowserSoon($page, fn (): mixed => DB::table('pricing.category_discounts')->where('category_id', $ready['category'])->value('id'), fn (mixed $id): bool => $id !== null);

    expect($discount)->not->toBeNull()
        ->and(browserSoon($page, "document.body.textContent.includes('Discount added')"))->toBeTrue();

    $page->navigate("/admin/category-discounts/{$discount}", BROWSER_SLOW_PAGE_LOAD);

    $category = str_replace('Drawer ', 'Category ', $ready['name']);

    expect(browserSoon($page, "document.querySelector('[data-test=\"discount-fields\"]')?.textContent.includes('{$category}')"))->toBeTrue()
        ->and(browserSoon($page, "document.querySelector('[data-test=\"discount-preview\"]')?.textContent.includes('Reaches 2 sizes in 1 product')"))->toBeTrue();
});

it('adds a band in the bands dialog, and removes it', function () {
    $ready = pricingBrowserProduct();
    [$size] = $ready['sizes'];
    pricingBrowserPrice('sa', [$size => '120.00']);
    Fx::asSystem(fn () => app(SetSellingTermsHandler::class)->handle(new SetSellingTerms(Fx::storeId('sa'), $ready['product'], [$size => ['retail' => true, 'wholesale' => true]], 1, null, 5, null)));
    Fx::asSystem(fn () => app(SetWholesalePricesHandler::class)->handle(new SetWholesalePrices(Fx::storeId('sa'), $size, [5 => '100.00'])));
    $bands = fn (): int => DB::table('pricing.wholesale_bands')->where('store_id', Fx::storeId('sa'))->where('variant_id', $size)->count();
    $page = pricingBrowser();
    $page->navigate("/admin/wholesale-prices?q={$ready['codes'][0]}", BROWSER_SLOW_PAGE_LOAD);

    $page->click("[data-test=\"wholesale-actions-{$size}\"]")->click('[data-test="edit-wholesale"]');

    expect(browserSoon($page, "document.querySelector('[data-test=\"band-0-from\"]') !== null"))->toBeTrue();

    $page->click('[data-test="add-band"]')
        ->type('[data-test="band-1-from"]', '10')
        ->type('[data-test="band-1-price"]', '95')
        ->click('[data-test="confirm-bands"]');

    expect(pricingBrowserSoon($page, $bands, fn (int $count): bool => $count === 2))->toBe(2)
        ->and(browserSoon($page, "document.body.textContent.includes('Wholesale prices saved')"))->toBeTrue();

    $page->click("[data-test=\"wholesale-actions-{$size}\"]")->click('[data-test="edit-wholesale"]');

    expect(browserSoon($page, "document.querySelector('[data-test=\"band-1-remove\"]') !== null"))->toBeTrue();

    $page->click('[data-test="band-1-remove"]')->click('[data-test="confirm-bands"]');

    expect(pricingBrowserSoon($page, $bands, fn (int $count): bool => $count === 1))->toBe(1);
});

it('prices a size off Needs a Price, which then leaves the list', function () {
    $ready = pricingBrowserProduct();
    [$size] = $ready['sizes'];
    $page = pricingBrowser();
    $page->navigate("/admin/needs-a-price?q={$ready['codes'][0]}", BROWSER_SLOW_PAGE_LOAD);

    expect(browserSoon($page, "document.getElementById('price-{$size}') !== null"))->toBeTrue();

    $page->type("#price-{$size}", '99.00')->click('[data-test="save-bar-save"]');

    expect(pricingBrowserSoon($page, fn (): ?int => pricingBrowserRetail('sa', $size), fn (?int $minor): bool => $minor === 9900))->toBe(9900)
        ->and(browserSoon($page, "document.querySelector('[data-test=\"needs-row-{$size}\"]') === null"))->toBeTrue();
});

it('draws the product page\'s Pricing section in two store cards, each with boxes of its own', function () {
    $ready = pricingBrowserProduct(['sa', 'eg']);
    [$size] = $ready['sizes'];
    pricingBrowserPrice('sa', [$size => '120.00']);
    pricingBrowserPrice('eg', [$size => '1500.00']);
    $page = pricingBrowser();
    $page->navigate("/admin/products/{$ready['product']}?tab=stores", BROWSER_SLOW_PAGE_LOAD);

    expect(browserSoon($page, "document.querySelector('[data-test=\"pricing-section-sa\"]') !== null && document.querySelector('[data-test=\"pricing-section-eg\"]') !== null"))->toBeTrue()
        ->and(browserSoon($page, "document.getElementById('price-sa-{$size}')?.value === '120.00' && document.getElementById('price-eg-{$size}')?.value === '1500.00'"))->toBeTrue();

    $page->type("#price-eg-{$size}", '1600.00');

    // Only the Egyptian card's bar: the Saudi card's boxes are untouched.
    expect(browserSoon($page, "document.getElementById('price-eg-save-bar-message') !== null && document.getElementById('price-sa-save-bar-message') === null"))->toBeTrue();

    $page->click('[data-test="pricing-section-eg"] [data-test="save-bar-save"]');

    expect(pricingBrowserSoon($page, fn (): ?int => pricingBrowserRetail('eg', $size), fn (?int $minor): bool => $minor === 160000))->toBe(160000)
        ->and(pricingBrowserRetail('sa', $size))->toBe(12000);
});

it('gives focus back inside the store\'s own card when its last offer is removed on a product\'s Stores tab', function () {
    $ready = pricingBrowserProduct(['sa', 'eg']);
    [$size] = $ready['sizes'];
    pricingBrowserPrice('sa', [$size => '120.00']);
    pricingBrowserPrice('eg', [$size => '1500.00']);
    $later = now()->addDays(3)->utc()->format('Y-m-d H:i:s').'+00';
    $sales = [];

    foreach (['sa', 'eg'] as $store) {
        $sales[$store] = Fx::asSystem(fn (): string => app(AddSaleHandler::class)->handle(new AddSale(Fx::storeId($store), null, [$size], '10', null, $later, null)));
    }

    $page = pricingBrowser();
    $page->navigate("/admin/products/{$ready['product']}?tab=stores", BROWSER_SLOW_PAGE_LOAD);

    expect(browserSoon($page, "document.querySelectorAll('[data-test^=\"offer-actions-\"]').length === 2"))->toBeTrue();

    // The card drawn first: removing its one offer, the next ⋯ on the page is the other store's.
    $first = (string) $page->script("() => document.querySelector('[data-test^=\"pricing-section-\"]').getAttribute('data-test').slice('pricing-section-'.length)");
    $other = $first === 'sa' ? 'eg' : 'sa';
    $page->click("[data-test=\"offer-actions-{$sales[$first]}\"]")
        ->click('[data-test="remove-offer"]')
        ->click('[data-test="confirm-remove-offer"]');

    expect(browserSoon($page, "document.querySelector('[data-test=\"offer-actions-{$sales[$first]}\"]') === null && document.querySelector('[role=\"alertdialog\"]') === null"))->toBeTrue()
        ->and(browserSoon($page, "document.activeElement?.getAttribute('data-test') === 'section-add-offer-{$first}'"))->toBeTrue()
        ->and(browserSoon($page, "document.querySelector('[data-test=\"offer-actions-{$sales[$other]}\"]') !== null"))->toBeTrue();
});
