<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
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
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogFixtures as Cx;

use function Pest\Laravel\seed;

/*
| The shop's pages in a real browser (catalog.md §4.5): the menu in the header leading to a category
| and its product, a choice picked, the search box suggesting while typing and Enter opening the
| results, and the pages right to left in Arabic at a phone's width - the menu in its Sheet - with
| nothing wider than the screen. The feature tests prove what each page is handed.
|
| No RefreshDatabase, as in every browser test here: the rows stay, so every name is made new.
*/

beforeEach(function () {
    seed(PlatformSeeder::class);
});

/**
 * A ready product with two widths, under a sub-category of a top category, listed in Saudi Arabia,
 * every name new (the clock's digits).
 *
 * @return array{n: string, top: string, category: string, product: string}
 */
function shopBrowserProduct(): array
{
    $n = substr((string) hrtime(true), -12);

    return Fx::asSystem(function () use ($n): array {
        $brand = app(AddBrandHandler::class)->handle(new AddBrand("ماركة {$n}", "Brand {$n}", 'DISTRIBUTOR'));
        $top = app(AddCategoryHandler::class)->handle(new AddCategory("مطابخ {$n}", "Kitchens {$n}"));
        $category = app(AddCategoryHandler::class)->handle(new AddCategory("أدراج {$n}", "Drawers {$n}", $top));
        $width = app(AddAttributeHandler::class)->handle(new AddAttribute("عرض {$n}", "Width {$n}", 'VARIANT'));
        $values = [
            app(AddAttributeValueHandler::class)->handle(new AddAttributeValue($width, "ستون {$n}", "60 cm {$n}")),
            app(AddAttributeValueHandler::class)->handle(new AddAttributeValue($width, "ثمانون {$n}", "80 cm {$n}")),
        ];
        $product = app(CreateProductHandler::class)->handle(new CreateProduct("درج {$n}", "Drawer {$n}", $brand));
        app(AddVariantAttributeHandler::class)->handle(new AddVariantAttribute($product, $width, []));
        $text = ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'A soft-closing drawer.']]]]];
        app(EditProductDetailsHandler::class)->handle(new EditProductDetails($product, "درج {$n}", "Drawer {$n}", $brand, descriptionAr: $text, descriptionEn: $text, categoryId: $category));

        foreach ($values as $at => $value) {
            app(AddVariantHandler::class)->handle(new AddVariant($product, substr($n, -8).$at, [$width => $value]));
        }

        app(SetProductGalleryHandler::class)->handle(new SetProductGallery($product, [Cx::media()]));
        app(MarkProductReadyHandler::class)->handle(new MarkProductReady($product));
        app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId('sa'), $product, true));

        return ['n' => $n, 'top' => $top, 'category' => $category, 'product' => $product];
    });
}

function shopBrowserSlug(string $kind, string $id, string $locale = 'en'): string
{
    return (string) DB::table("catalog.{$kind}_slugs")->where("{$kind}_id", $id)->where('locale', $locale)->where('is_current', true)->value('slug');
}

it('leads from the menu to a category, to its product, and picks a choice', function () {
    ['n' => $n, 'product' => $product] = shopBrowserProduct();
    $page = visit('/sa/en');
    $page->resize(1280, 900);

    // The mega menu: All Categories opens the top categories with their sub-categories.
    $page->click('[data-test="all-categories"]');
    expect(browserUntil($page, "[...document.querySelectorAll('a')].some((a) => a.textContent.trim() === 'Drawers {$n}')"))->toBeTrue();
    $page->click("Drawers {$n}");

    expect(browserUntil($page, "window.location.pathname.startsWith('/sa/en/categories/') && document.querySelector('h1')?.textContent === 'Drawers {$n}'"))->toBeTrue()
        ->and(browserUntil($page, "document.querySelectorAll('[data-test=\"card\"]').length === 1"))->toBeTrue();

    $page->click('[data-test="card"]');

    expect(browserUntil($page, "window.location.pathname === '/sa/en/products/".shopBrowserSlug('product', $product)."'"))->toBeTrue()
        ->and(browserUntil($page, "document.querySelectorAll('[data-test=\"choice-value\"]').length === 2"))->toBeTrue()
        ->and($page->script("document.querySelector('[data-test=\"choice-value\"][aria-pressed=\"true\"]')?.textContent"))->toContain("60 cm {$n}");

    $page->script("[...document.querySelectorAll('[data-test=\"choice-value\"]')][1].click()");

    expect(browserUntil($page, "document.querySelector('[data-test=\"choice-value\"][aria-pressed=\"true\"]')?.textContent.includes('80 cm {$n}')"))->toBeTrue()
        // No code, no price, no Add to Cart.
        ->and($page->script("document.body.textContent.includes('".substr($n, -8)."0')"))->toBeFalse();

    $page->assertNoJavaScriptErrors();
});

it('suggests while typing, and Enter opens the results', function () {
    ['n' => $n, 'product' => $product] = shopBrowserProduct();
    $page = visit('/sa/en');
    $page->resize(1280, 900);

    $page->click('[data-test="search-input"]');
    // The suggestions' half is loaded on the way in, and takes the field's place, focused.
    expect(browserUntil($page, "document.activeElement?.getAttribute('data-test') === 'search-input' && document.activeElement.hasAttribute('cmdk-input')"))->toBeTrue();
    $page->type('[data-test="search-input"]', $n);

    expect(browserUntil($page, "[...document.querySelectorAll('[data-test=\"suggestion\"]')].some((item) => item.textContent.includes('Drawer {$n}'))"))->toBeTrue();

    $page->keys('[data-test="search-input"]', 'Enter');

    expect(browserUntil($page, "window.location.pathname === '/sa/en/search' && new URLSearchParams(window.location.search).get('q') === '{$n}'"))->toBeTrue()
        ->and(browserUntil($page, "document.querySelector('[data-test=\"search-heading\"]')?.textContent.includes('{$n}')"))->toBeTrue()
        ->and($page->script("document.querySelectorAll('[data-test=\"result\"]').length"))->toBe(1)
        ->and(DB::table('catalog.search_log')->where('query', $n)->count())->toBe(1);

    expect(shopBrowserSlug('product', $product))->not->toBe('');
    $page->assertNoJavaScriptErrors();
});

it('reads right to left in Arabic, at a phone\'s width, the menu in its Sheet, nothing wider than the screen', function () {
    ['n' => $n, 'category' => $category, 'product' => $product] = shopBrowserProduct();
    $page = visit('/sa/ar');
    $page->resize(375, 812);

    $page->click('[data-test="category-sheet-open"]');
    // From the side the page starts on, once it has slid in: against the right, three quarters wide
    // (measured 2026-10-12: 91 to 365 of 375, the last 10 the scroll lock's gap).
    expect(browserUntil($page, "(() => { const r = document.querySelector('[data-test=\"category-sheet\"]').getBoundingClientRect(); return r.left > window.innerWidth / 5 && r.right > window.innerWidth * 0.9; })()"))->toBeTrue()
        ->and(browserUntil($page, "[...document.querySelectorAll('[data-test=\"category-sheet\"] button')].some((b) => b.textContent.includes('مطابخ {$n}'))"))->toBeTrue();
    $page->script("document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))");

    foreach (['/sa/ar/categories/'.rawurlencode(shopBrowserSlug('category', $category, 'ar')) => 'card', '/sa/ar/products/'.rawurlencode(shopBrowserSlug('product', $product, 'ar')) => 'choice-value', "/sa/ar/search?q={$n}" => 'result'] as $address => $shown) {
        expect(browserUntil($page, "document.readyState === 'complete'"))->toBeTrue();
        $page->navigate($address, BROWSER_PAGE_LOAD);

        expect(browserUntil($page, "document.querySelector('[data-test=\"{$shown}\"]') !== null"))->toBeTrue()
            ->and($page->script('document.documentElement.dir'))->toBe('rtl')
            ->and($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
    }

    $page->assertNoJavaScriptErrors();
});
