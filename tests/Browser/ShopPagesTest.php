<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
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
use Modules\Catalog\Application\Command\EditCategory\EditCategory;
use Modules\Catalog\Application\Command\EditCategory\EditCategoryHandler;
use Modules\Catalog\Application\Command\EditProductDetails\EditProductDetails;
use Modules\Catalog\Application\Command\EditProductDetails\EditProductDetailsHandler;
use Modules\Catalog\Application\Command\MarkProductReady\MarkProductReady;
use Modules\Catalog\Application\Command\MarkProductReady\MarkProductReadyHandler;
use Modules\Catalog\Application\Command\SetProductGallery\SetProductGallery;
use Modules\Catalog\Application\Command\SetProductGallery\SetProductGalleryHandler;
use Modules\Catalog\Application\Command\UpdateVariant\UpdateVariant;
use Modules\Catalog\Application\Command\UpdateVariant\UpdateVariantHandler;
use Modules\Catalog\Public\Contracts\ListingFacts;
use Modules\Inventory\Public\Contracts\InventoryApi;
use Modules\Inventory\Public\Dto\HoldLineDto;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogFixtures as Cx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Pricing\Support\PricingFixtures as Rx;

use function Pest\Laravel\seed;

/*
| The shop's pages in a real browser (catalog.md §4.5): the menu in the header leading to a category
| and its product, a choice picked, the search box suggesting while typing and Enter opening the
| results, a sold-out size greyed and out of reach (amendment 39), and the pages right to left in Arabic
| at a phone's width - the menu in its Sheet - with nothing wider than the screen. The feature tests prove what each page is handed.
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
        Px::onSale('sa', $product);

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
    // Clicked in the page: the browser suite keeps its data, so this run's category sits at the end of a panel that
    // grows with every run, past where a pointer's click can reach it (2026-11-20).
    $page->script("[...document.querySelectorAll('[data-test=\"all-categories-panel\"] a')].find((a) => a.textContent.trim() === 'Drawers {$n}').click()");

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

it('greys a sold-out size and never lets it be chosen, on a desktop and at a phone\'s width, in both languages', function (int $width, int $height) {
    ['n' => $n, 'product' => $product] = shopBrowserProduct();
    [$sixty, $eighty] = array_map('strval', DB::table('catalog.variants')->where('product_id', $product)->orderBy('position')->pluck('id')->all());
    Rx::set('sa', [$sixty => '30.00', $eighty => '25.00']);
    app(InventoryApi::class)->hold(StoreId::fromString(Fx::storeId('sa')), strtolower((string) Str::ulid()), [new HoldLineDto($sixty, 5)], null);

    $page = visit('/sa/en/products/'.shopBrowserSlug('product', $product));
    $page->resize($width, $height);
    $button = "[...document.querySelectorAll('[data-test=\"choice-value\"]')]";

    expect(browserUntil($page, "{$button}.length === 2"))->toBeTrue()
        // Shown, greyed, read aloud as not available, and out of reach.
        ->and($page->script("{$button}[0].disabled"))->toBeTrue()
        ->and($page->script("{$button}[0].getAttribute('aria-label')"))->toBe("60 cm {$n}, not available")
        ->and($page->script("getComputedStyle({$button}[0]).textDecorationLine"))->toBe('line-through')
        // The page opens on the size left, at its price.
        ->and($page->script("{$button}[1].getAttribute('aria-pressed')"))->toBe('true')
        ->and($page->script("{$button}[0].getAttribute('aria-pressed')"))->toBe('false')
        ->and($page->script("document.body.innerText.includes('25.00')"))->toBeTrue()
        ->and($page->script("document.body.innerText.includes('30.00')"))->toBeFalse();

    $page->script("{$button}[0].click()");

    expect($page->script("{$button}[1].getAttribute('aria-pressed')"))->toBe('true')
        ->and($page->script("{$button}[0].getAttribute('aria-pressed')"))->toBe('false')
        ->and(browserSoon($page, 'document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();

    $page->navigate('/sa/ar/products/'.rawurlencode(shopBrowserSlug('product', $product, 'ar')), BROWSER_PAGE_LOAD);

    expect(browserUntil($page, "{$button}.length === 2 && document.documentElement.dir === 'rtl'"))->toBeTrue()
        ->and($page->script("{$button}[0].disabled"))->toBeTrue()
        ->and($page->script("{$button}[0].getAttribute('aria-label')"))->toBe("ستون {$n}، غير متاح")
        ->and($page->script("{$button}[1].getAttribute('aria-pressed')"))->toBe('true')
        ->and(browserSoon($page, 'document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();

    $page->assertNoJavaScriptErrors();
})->with([
    'a desktop' => [1280, 900],
    'a phone' => [375, 812],
]);

it('greys a value that names a sold-out size with the other choices as they are, and picking it moves to a size left', function () {
    $n = substr((string) hrtime(true), -12);
    $product = Fx::asSystem(function () use ($n): string {
        $brand = app(AddBrandHandler::class)->handle(new AddBrand("ماركة {$n}", "Brand {$n}", 'DISTRIBUTOR'));
        $category = app(AddCategoryHandler::class)->handle(new AddCategory("مطابخ {$n}", "Kitchens {$n}"));
        $width = app(AddAttributeHandler::class)->handle(new AddAttribute("عرض {$n}", "Width {$n}", 'VARIANT'));
        $finish = app(AddAttributeHandler::class)->handle(new AddAttribute("لمسة {$n}", "Finish {$n}", 'VARIANT'));
        $sixty = app(AddAttributeValueHandler::class)->handle(new AddAttributeValue($width, "ستون {$n}", "60 cm {$n}"));
        $eighty = app(AddAttributeValueHandler::class)->handle(new AddAttributeValue($width, "ثمانون {$n}", "80 cm {$n}"));
        $matte = app(AddAttributeValueHandler::class)->handle(new AddAttributeValue($finish, "مطفي {$n}", "Matte {$n}"));
        $gloss = app(AddAttributeValueHandler::class)->handle(new AddAttributeValue($finish, "لامع {$n}", "Gloss {$n}"));
        $product = app(CreateProductHandler::class)->handle(new CreateProduct("درج {$n}", "Drawer {$n}", $brand));
        app(AddVariantAttributeHandler::class)->handle(new AddVariantAttribute($product, $width, []));
        app(AddVariantAttributeHandler::class)->handle(new AddVariantAttribute($product, $finish, []));
        $text = ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'A drawer.']]]]];
        app(EditProductDetailsHandler::class)->handle(new EditProductDetails($product, "درج {$n}", "Drawer {$n}", $brand, descriptionAr: $text, descriptionEn: $text, categoryId: $category));

        foreach ([[$sixty, $matte], [$eighty, $matte], [$sixty, $gloss]] as $at => [$w, $f]) {
            app(AddVariantHandler::class)->handle(new AddVariant($product, substr($n, -8).$at, [$width => $w, $finish => $f]));
        }

        app(SetProductGalleryHandler::class)->handle(new SetProductGallery($product, [Cx::media()]));
        app(MarkProductReadyHandler::class)->handle(new MarkProductReady($product));
        app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId('sa'), $product, true));

        return $product;
    });
    // 60 cm Matte sold out; 80 cm Matte and 60 cm Gloss left.
    $sizes = array_map('strval', DB::table('catalog.variants')->where('product_id', $product)->orderBy('position')->pluck('id')->all());
    Px::onSale('sa', $product);
    app(InventoryApi::class)->hold(StoreId::fromString(Fx::storeId('sa')), strtolower((string) Str::ulid()), [new HoldLineDto($sizes[0], 5)], null);

    // 60 cm Gloss ending soon: no badge on the greyed 60 cm that leads to it, until it is chosen.
    app(ListingFacts::class)->endingSoon(StoreId::fromString(Fx::storeId('sa')), [$sizes[2]], true);

    $page = visit('/sa/en/products/'.shopBrowserSlug('product', $product));
    $page->resize(1280, 900);
    $named = static fn (string $name): string => "[...document.querySelectorAll('[data-test=\"choice-value\"]')].find((b) => b.textContent.includes('{$name} {$n}'))";

    // It opens on the first size left, 80 cm Matte: with Matte chosen, 60 cm names the sold-out size.
    expect(browserUntil($page, $named('60 cm').' !== undefined'))->toBeTrue()
        ->and($page->script($named('80 cm').".getAttribute('aria-pressed')"))->toBe('true')
        ->and($page->script($named('Matte').".getAttribute('aria-pressed')"))->toBe('true')
        ->and($page->script($named('60 cm').'.disabled'))->toBeFalse()
        ->and($page->script('getComputedStyle('.$named('60 cm').').textDecorationLine'))->toBe('line-through')
        // What pressing it does, the value's own text still on the button.
        ->and($page->script($named('60 cm').".getAttribute('aria-label')"))->toBe("60 cm {$n}, available in another option")
        ->and($page->script($named('60 cm').'.textContent'))->toContain("60 cm {$n}")
        ->and($page->script($named('60 cm').".querySelector('[data-test=\"last-pieces\"]') === null"))->toBeTrue()
        ->and($page->script('getComputedStyle('.$named('Gloss').').textDecorationLine'))->toBe('none');

    // Picked, it moves to the size left that holds it, never to the sold-out one.
    $page->script($named('60 cm').'.click()');

    expect(browserUntil($page, $named('60 cm').".getAttribute('aria-pressed') === 'true'"))->toBeTrue()
        ->and($page->script($named('Gloss').".getAttribute('aria-pressed')"))->toBe('true')
        ->and($page->script($named('Matte').".getAttribute('aria-pressed')"))->toBe('false')
        ->and($page->script('getComputedStyle('.$named('Matte').').textDecorationLine'))->toBe('line-through')
        ->and($page->script($named('60 cm').".querySelector('[data-test=\"last-pieces\"]') !== null"))->toBeTrue()
        ->and($page->script('getComputedStyle('.$named('60 cm').').textDecorationLine'))->toBe('none');

    $page->assertNoJavaScriptErrors();
});

it('reads right to left in Arabic, at a phone\'s width, the menu in its Sheet, nothing wider than the screen', function () {
    ['n' => $n, 'category' => $category, 'product' => $product] = shopBrowserProduct();
    $page = visit('/sa/ar');
    $page->resize(375, 812);

    $page->click('[data-test="category-sheet-open"]');
    // From the side the page starts on, once it has slid in: against the right, three quarters wide
    // (measured 2026-10-12: 91 to 365 of 375, the last 10 the scroll lock's gap). Since 2026-11-11 the drawer of
    // content.md §1.5 (the owner's option C3), the design's min(320px, 86vw): 45 to 365.
    expect(browserUntil($page, "(() => { const r = document.querySelector('[data-test=\"category-sheet\"]').getBoundingClientRect(); return r.left > 20 && r.right > window.innerWidth * 0.9; })()"))->toBeTrue()
        ->and(browserUntil($page, "[...document.querySelectorAll('[data-test=\"category-sheet\"] button')].some((b) => b.textContent.includes('مطابخ {$n}'))"))->toBeTrue();
    $page->script("document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))");

    foreach (['/sa/ar/categories/'.rawurlencode(shopBrowserSlug('category', $category, 'ar')) => 'card', '/sa/ar/products/'.rawurlencode(shopBrowserSlug('product', $product, 'ar')) => 'choice-value', "/sa/ar/search?q={$n}" => 'result'] as $address => $shown) {
        expect(browserUntil($page, "document.readyState === 'complete'"))->toBeTrue();
        $page->navigate($address, BROWSER_PAGE_LOAD);

        expect(browserUntil($page, "document.querySelector('[data-test=\"{$shown}\"]') !== null"))->toBeTrue()
            ->and($page->script('document.documentElement.dir'))->toBe('rtl')
            ->and(browserSoon($page, 'document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
    }

    $page->assertNoJavaScriptErrors();
});

it('draws the specifications as the design: one box with the tabs inside, rows that wrap, measures in mono (flags-2026-11.md S2.1, F7)', function () {
    ['n' => $n, 'product' => $product] = shopBrowserProduct();
    $long = 'A long finish description that has to wrap onto a second line inside the value column, never cut and never pushing the page wider, '.$n;

    Fx::asSystem(function () use ($n, $product, $long): void {
        $finish = app(AddAttributeHandler::class)->handle(new AddAttribute("لمسة {$n}", "Finish note {$n}", 'INFORMATIONAL'));
        // A measure: a number kept with its attribute's unit (F7: mono, left to right).
        $load = app(AddAttributeHandler::class)->handle(new AddAttribute("حمل {$n}", "Load {$n}", 'INFORMATIONAL', 'كجم', 'kg'));
        // A number typed as text is not a measure (owner, 2026-11-20): the page's face, the page's direction.
        $pieces = app(AddAttributeHandler::class)->handle(new AddAttribute("عدد {$n}", "Pieces {$n}", 'INFORMATIONAL'));
        $variants = DB::table('catalog.variants')->where('product_id', $product)->orderBy('position')->get(['id', 'code']);

        foreach ($variants as $variant) {
            $values = DB::table('catalog.variant_values')->where('variant_id', $variant->id)->pluck('value_id', 'attribute_id')->all();
            app(UpdateVariantHandler::class)->handle(new UpdateVariant(
                (string) $variant->id,
                (string) $variant->code,
                $values,
                [$finish => ['text_ar' => $long, 'text_en' => $long], $load => ['number' => '35'], $pieces => ['text_ar' => '50 حبة', 'text_en' => '50 pcs']],
                weightGrams: 1450,
                lengthMm: 350,
                widthMm: 450,
                heightMm: 20,
            ));
        }
    });

    $page = visit('/sa/en/products/'.shopBrowserSlug('product', $product));
    $page->resize(1440, 900);

    $box = "document.querySelector('[data-test=\"product-tabs\"]')";
    expect(browserUntil($page, "{$box} !== null"))->toBeTrue();

    $page->click('[data-test="tab-specs"]');

    // Each row's value by its text, as [is it the mono face, its own direction] (a measure says ltr).
    $value = static fn (string $text): string => "(() => { const dd = [...{$box}.querySelectorAll('[data-test=\"detail-row\"] dd')].find((d) => d.textContent === '{$text}'); return dd ? [getComputedStyle(dd).fontFamily.includes('Plex Mono'), dd.dir] : null; })()";
    // The brand token's colour, as the browser computes it in the theme shown.
    $brand = "(() => { const i = document.createElement('i'); i.style.color = 'var(--tw-brand)'; document.body.append(i); const c = getComputedStyle(i).color; i.remove(); return c; })()";

    expect(browserUntil($page, "{$box}.querySelectorAll('[data-test=\"detail-row\"]').length === 5"))->toBeTrue()
        ->and($page->script("getComputedStyle({$box}).borderTopWidth"))->toBe('1px')
        ->and($page->script("getComputedStyle({$box}).borderTopLeftRadius"))->toBe('12px')
        // The tabs are the box's strip: each tab inside the strip's height, the strip's line under it (the strip once
        // stayed 36 px tall while a tab was 52, so the tabs spilled out of it - the review of 3a).
        ->and($page->script("(() => { const list = {$box}.querySelector('[role=\"tablist\"]').getBoundingClientRect(); const tab = document.querySelector('[data-test=\"tab-specs\"]').getBoundingClientRect(); return tab.top >= list.top - 0.5 && list.bottom - tab.bottom >= 0 && list.bottom - tab.bottom <= 1.5; })()"))->toBeTrue('a tab spills out of the strip')
        // A screen reader keeps each label with its value: a description list.
        ->and($page->script("{$box}.querySelector('[data-test=\"details\"]').tagName + ' ' + {$box}.querySelector('[data-test=\"detail-row\"]').firstElementChild.tagName"))->toBe('DL DT')
        // A long value wraps: its row is taller than a one-line row.
        ->and($page->script("(() => { const h = [...{$box}.querySelectorAll('[data-test=\"detail-row\"]')].map((row) => row.getBoundingClientRect().height); return Math.max(...h) > Math.min(...h) + 15; })()"))->toBeTrue()
        // Only measures in the mono face, left to right: the number kept with its unit and the weight; not a number
        // typed as text, nor plain text.
        ->and($page->script($value('35 kg')))->toBe([true, 'ltr'])
        ->and($page->script($value('1450 g')))->toBe([true, 'ltr'])
        ->and($page->script($value('50 pcs')))->toBe([false, ''])
        ->and($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue()
        // The active tab: a 2 px underline in the brand's colour (waited for, as it fades in).
        ->and(browserSoon($page, "(() => { const line = getComputedStyle(document.querySelector('[data-test=\"tab-specs\"]'), '::after'); return line.height === '2px' && line.opacity === '1' && line.backgroundColor === {$brand}; })()"))->toBeTrue('the active tab has no 2 px brand underline');

    // Arabic: right to left; a measure still reads left to right, a number typed as text reads with the page.
    $page->navigate('/sa/ar/products/'.shopBrowserSlug('product', $product, 'ar'), BROWSER_PAGE_LOAD);
    expect(browserUntil($page, "document.documentElement.dir === 'rtl' && document.querySelector('[data-test=\"tab-specs\"]') !== null"))->toBeTrue();
    $page->click('[data-test="tab-specs"]');
    expect(browserUntil($page, "{$box}.querySelectorAll('[data-test=\"detail-row\"]').length === 5"))->toBeTrue()
        ->and($page->script('(() => { const d = '.$value('35 كجم').'; return d && d[1]; })()'))->toBe('ltr')
        ->and($page->script('(() => { const d = '.$value('50 حبة').'; return d && d[1]; })()'))->toBe('')
        ->and($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();

    // A phone keeps its folds, the tabs hidden, with nothing sideways.
    $page->resize(375, 812);
    expect(browserSoon($page, "getComputedStyle(document.querySelector('[data-test=\"product-folds\"]')).display !== 'none' && getComputedStyle({$box}).display === 'none' && document.documentElement.scrollWidth <= window.innerWidth"))->toBeTrue();
    $page->assertNoJavaScriptErrors();

    // Dark mode: the active tab's words and its underline are the brand's colour there too.
    $dark = visit('/sa/en/products/'.shopBrowserSlug('product', $product))->inDarkMode();
    $dark->resize(1440, 900);
    expect(browserUntil($dark, "document.documentElement.dataset.mode === 'dark' && {$box} !== null"))->toBeTrue();
    $dark->click('[data-test="tab-specs"]');
    expect(browserSoon($dark, "(() => { const tab = document.querySelector('[data-test=\"tab-specs\"]'); const line = getComputedStyle(tab, '::after'); return getComputedStyle(tab).color === {$brand} && line.opacity === '1' && line.backgroundColor === {$brand}; })()"))->toBeTrue('the active tab is not brand in dark mode');
});

it('keeps the category row to whole names that fit, «Order wholesale» always on the screen', function () {
    // Long names, so the row cannot hold them all on any database: before, the row kept its count by the screen's width
    // and pushed «Order wholesale» past the edge (1333 px on a 1280 px window; owner, 2026-11-20: drop what doesn't fit).
    foreach (range(1, 5) as $i) {
        ['n' => $n, 'top' => $top] = shopBrowserProduct();
        Fx::asSystem(fn () => app(EditCategoryHandler::class)->handle(new EditCategory($top, "مستلزمات المطابخ والخزائن الطويلة {$n}", "Long kitchen and wardrobe fittings {$n}")));
    }

    $row = "(() => { const area = document.querySelector('[data-test=\"category-menu\"] [data-slot=\"navigation-menu-list\"]').parentElement.closest('div.min-w-0').getBoundingClientRect(); const shown = [...document.querySelectorAll('[data-test=\"top-category\"]')].filter((a) => a.getClientRects().length > 0); const wholesale = document.querySelector('[data-test=\"order-wholesale\"]').getBoundingClientRect(); return shown.length > 0 && shown.every((a) => { const r = a.getBoundingClientRect(); return r.left >= area.left - 0.5 && r.right <= area.right + 0.5; }) && wholesale.right <= window.innerWidth && wholesale.left >= 0 && document.documentElement.scrollWidth <= window.innerWidth; })()";

    $page = visit('/sa/en');
    foreach ([[1100, 900], [1280, 900]] as [$width, $height]) {
        $page->resize($width, $height);
        $page->navigate('/sa/en', BROWSER_PAGE_LOAD);
        expect(browserSoon($page, $row))->toBeTrue("the category row does not fit at {$width} px");
    }

    // Arabic mirrors it: the same rule from the right.
    $page->navigate('/sa/ar', BROWSER_PAGE_LOAD);
    expect(browserSoon($page, "document.documentElement.dir === 'rtl' && {$row}"))->toBeTrue('the Arabic category row does not fit');
    $page->assertNoJavaScriptErrors();
});
