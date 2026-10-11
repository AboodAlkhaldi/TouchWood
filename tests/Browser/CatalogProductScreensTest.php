<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Application\CatalogPermissions as P;
use Modules\Catalog\Application\Command\AddAttribute\AddAttribute;
use Modules\Catalog\Application\Command\AddAttribute\AddAttributeHandler;
use Modules\Catalog\Application\Command\AddAttributeValue\AddAttributeValue;
use Modules\Catalog\Application\Command\AddAttributeValue\AddAttributeValueHandler;
use Modules\Catalog\Application\Command\AddBrand\AddBrand;
use Modules\Catalog\Application\Command\AddBrand\AddBrandHandler;
use Modules\Catalog\Application\Command\AddVariant\AddVariant;
use Modules\Catalog\Application\Command\AddVariant\AddVariantHandler;
use Modules\Catalog\Application\Command\AddVariantAttribute\AddVariantAttribute;
use Modules\Catalog\Application\Command\AddVariantAttribute\AddVariantAttributeHandler;
use Modules\Catalog\Application\Command\CreateProduct\CreateProduct;
use Modules\Catalog\Application\Command\CreateProduct\CreateProductHandler;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\seed;

/*
| Catalog's products screens, in a real browser (catalog.md §4.4 S8, S9).
|
| The feature tests beside them prove what each page is handed and what the server does with what it
| sends. These prove a person can use them: adding a draft opens its page, which says what it still
| lacks; the tabs live in the address; the details save; a variant is added from its dialog; a search
| word is added; the page reads right to left in Arabic at a phone's width.
|
| No RefreshDatabase, for the reason written at the top of StaffScreensTest: every name here is made
| unique, as the rows stay. A photo is not chosen here - the plugin's server receives no file (lesson
| 117); the feature tests upload them.
*/

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

/**
 * @param  list<string>  $permissions
 */
function catalogProductBrowser(array $permissions, string $locale = 'en'): mixed
{
    $staffId = Fx::staffWith($permissions, ['*']);
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
function catalogProductBrowserSoon(mixed $page, Closure $read, Closure $holds): mixed
{
    $value = $read();

    for ($tries = 0; $tries < 50 && ! $holds($value); $tries++) {
        $page->wait(0.1);
        $value = $read();
    }

    return $value;
}

/** A number for names that must be new, from the clock (CatalogListScreensTest). */
function catalogProductBrowserFresh(): string
{
    return substr((string) hrtime(true), -12);
}

/** A draft, on a brand of its own - which is the default one only if it is the first. */
function catalogProductBrowserDraft(string $n): string
{
    return Fx::asSystem(function () use ($n): string {
        $brand = app(AddBrandHandler::class)->handle(new AddBrand("ماركة {$n}", "Brand {$n}", 'DISTRIBUTOR'));

        return app(CreateProductHandler::class)->handle(new CreateProduct("درج {$n}", "Drawer {$n}", $brand));
    });
}

it('adds a draft from the list, whose page opens saying what it still lacks', function () {
    $n = catalogProductBrowserFresh();
    // A brand to put it on: the first one ever made is the default the dialog chooses.
    Fx::asSystem(fn () => app(AddBrandHandler::class)->handle(new AddBrand("ماركة {$n}", "Brand {$n}", 'DISTRIBUTOR')));
    $page = catalogProductBrowser([P::PRODUCT_VIEW, P::PRODUCT_CREATE]);
    $page->navigate('/admin/products', BROWSER_PAGE_LOAD);
    $page->assertSee('Products');

    $page->click('[data-test="add-product"]')
        ->type('#product-name-ar', "مفصلة {$n}")
        ->type('#product-name-en', "Hinge {$n}")
        ->click('[data-test="confirm-product"]');

    $id = catalogProductBrowserSoon($page, fn () => DB::table('catalog.products')->where('name_en', "Hinge {$n}")->value('id'), fn ($value): bool => $value !== null);

    expect($id)->not->toBeNull()
        ->and(browserUntil($page, "window.location.pathname === '/admin/products/{$id}'"))->toBeTrue()
        ->and(browserUntil($page, "document.querySelector('[data-test=\"missing\"]')?.textContent.includes('an Arabic description')"))->toBeTrue();

    $page->assertNoJavaScriptErrors();
});

it('keeps the tab in the address, and saves the details once something changed', function () {
    $n = catalogProductBrowserFresh();
    $product = catalogProductBrowserDraft($n);
    $page = catalogProductBrowser([P::PRODUCT_VIEW, P::PRODUCT_UPDATE]);
    $page->navigate("/admin/products/{$product}", BROWSER_PAGE_LOAD);

    // Nothing changed yet: Save Details is out of reach, and says why (the tab is fetched as it opens).
    expect(browserUntil($page, "document.querySelector('[data-test=\"save-details\"]')?.getAttribute('aria-disabled') === 'true'"))->toBeTrue();

    $page->type('#details-name-en', "Quiet Drawer {$n}")
        ->click('[data-test="save-details"]');

    $saved = catalogProductBrowserSoon($page, fn () => DB::table('catalog.products')->where('id', $product)->value('name_en'), fn ($value): bool => $value === "Quiet Drawer {$n}");

    expect($saved)->toBe("Quiet Drawer {$n}");

    $page->click('[data-test="tab-search"]');

    expect(browserUntil($page, "window.location.search.includes('tab=search')"))->toBeTrue()
        ->and(browserUntil($page, "document.querySelector('[data-test=\"search-word\"]') !== null"))->toBeTrue();

    $page->type('#search-word', "rail{$n}")->click('[data-test="add-word"]');

    $words = catalogProductBrowserSoon($page, fn () => DB::table('catalog.product_search_words')->where('product_id', $product)->pluck('word')->all(), fn ($value): bool => $value !== []);

    expect($words)->toBe(["rail{$n}"]);

    // By keyboard: an arrow moves to the next tab without opening it, Enter opens it - a page of its
    // own - and focus is back on the tab chosen, not at the top of the page.
    $page->keys('[data-test="tab-search"]', 'ArrowRight');

    expect(browserUntil($page, "document.activeElement?.getAttribute('data-test') === 'tab-related'"))->toBeTrue()
        ->and($page->script('window.location.search.includes("tab=search")'))->toBeTrue();

    $page->keys('[data-test="tab-related"]', 'Enter');

    expect(browserUntil($page, "window.location.search.includes('tab=related') && document.querySelector('[data-test=\"related-RELATED\"]') !== null"))->toBeTrue()
        ->and(browserUntil($page, "document.activeElement?.getAttribute('data-test') === 'tab-related'"))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

it('adds a variant from its dialog, with a value of the product\'s variant attribute', function () {
    $n = catalogProductBrowserFresh();
    $product = catalogProductBrowserDraft($n);
    [$width, $value] = Fx::asSystem(function () use ($n, $product): array {
        $width = app(AddAttributeHandler::class)->handle(new AddAttribute("عرض {$n}", "Width {$n}", 'VARIANT'));
        $value = app(AddAttributeValueHandler::class)->handle(new AddAttributeValue($width, "ستون {$n}", "60 cm {$n}"));
        app(AddVariantAttributeHandler::class)->handle(new AddVariantAttribute($product, $width, []));

        return [$width, $value];
    });
    $code = substr($n, -9);
    $page = catalogProductBrowser([P::PRODUCT_VIEW, P::PRODUCT_UPDATE]);
    $page->navigate("/admin/products/{$product}?tab=variants", BROWSER_PAGE_LOAD);

    $page->click('[data-test="add-variant"]')
        ->type('#variant-code', $code)
        ->select("#variant-value-{$width}", $value)
        ->click('[data-test="confirm-variant"]');

    $added = catalogProductBrowserSoon($page, fn () => DB::table('catalog.variants')->where('product_id', $product)->value('code'), fn ($found): bool => $found !== null);

    expect($added)->toBe($code)
        ->and(browserUntil($page, "[...document.querySelectorAll('tr[data-test^=\"variant-\"]')].some((row) => row.innerText.includes('60 cm {$n}'))"))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

it('turns Has Variants on, and adds a variant with a value made from its dialog', function () {
    $n = catalogProductBrowserFresh();
    $product = catalogProductBrowserDraft($n);
    $width = Fx::asSystem(fn (): string => app(AddAttributeHandler::class)->handle(new AddAttribute("عرض {$n}", "Width {$n}", 'VARIANT')));
    $code = substr($n, -9);
    $page = catalogProductBrowser([P::PRODUCT_VIEW, P::PRODUCT_UPDATE]);
    $page->navigate("/admin/products/{$product}?tab=variants", BROWSER_PAGE_LOAD);

    $page->click('[data-test="has-variants-switch"]')
        ->select('#attribute-choice', $width)
        ->click('[data-test="confirm-attribute"]');

    $held = catalogProductBrowserSoon($page, fn () => DB::table('catalog.product_attributes')->where('product_id', $product)->pluck('attribute_id')->all(), fn ($found): bool => $found !== []);

    expect($held)->toBe([$width])
        ->and(browserUntil($page, "document.querySelector('[data-test=\"has-variants-switch\"]')?.getAttribute('aria-checked') === 'true'"))->toBeTrue();

    $page->click('[data-test="add-variant"]')
        ->type('#variant-code', $code)
        ->click("[data-test=\"variant-value-{$width}-new\"]")
        ->type('#new-value-ar', "ستون {$n}")
        ->type('#new-value-en', "60 cm {$n}")
        ->click('[data-test="confirm-new-value"]');

    // The value made is chosen in the variant's dialog, still open.
    expect(browserUntil($page, "(document.querySelector('#variant-value-{$width}')?.value ?? '') !== ''"))->toBeTrue();

    $page->click('[data-test="confirm-variant"]');

    $added = catalogProductBrowserSoon($page, fn () => DB::table('catalog.variants')->where('product_id', $product)->value('code'), fn ($found): bool => $found !== null);

    expect($added)->toBe($code)
        ->and(browserUntil($page, "[...document.querySelectorAll('tr[data-test^=\"variant-\"]')].some((row) => row.innerText.includes('60 cm {$n}'))"))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

it('moves a variant to the top from its menu', function () {
    $n = catalogProductBrowserFresh();
    $product = catalogProductBrowserDraft($n);
    [$first, $second] = Fx::asSystem(function () use ($n, $product): array {
        $width = app(AddAttributeHandler::class)->handle(new AddAttribute("عرض {$n}", "Width {$n}", 'VARIANT'));
        $sixty = app(AddAttributeValueHandler::class)->handle(new AddAttributeValue($width, "ستون {$n}", "60 cm {$n}"));
        $eighty = app(AddAttributeValueHandler::class)->handle(new AddAttributeValue($width, "ثمانون {$n}", "80 cm {$n}"));
        app(AddVariantAttributeHandler::class)->handle(new AddVariantAttribute($product, $width, []));

        return [
            app(AddVariantHandler::class)->handle(new AddVariant($product, substr($n, -9), [$width => $sixty])),
            // Ten digits: no nine-digit code another test cut from the clock can be it.
            app(AddVariantHandler::class)->handle(new AddVariant($product, '2'.substr($n, -9), [$width => $eighty])),
        ];
    });
    $page = catalogProductBrowser([P::PRODUCT_VIEW, P::PRODUCT_UPDATE]);
    $page->navigate("/admin/products/{$product}?tab=variants", BROWSER_PAGE_LOAD);

    $page->click("[data-test=\"variant-actions-{$second}\"]")
        ->click('[data-test="variant-top"]');

    $order = catalogProductBrowserSoon($page, fn () => DB::table('catalog.variants')->where('product_id', $product)->orderBy('position')->pluck('id')->all(), fn ($found): bool => $found === [$second, $first]);

    expect($order)->toBe([$second, $first])
        ->and(browserUntil($page, "document.querySelector('tbody tr')?.getAttribute('data-test') === 'variant-{$second}'"))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});

it('reads right to left in Arabic, at a phone\'s width, with nothing wider than the screen', function () {
    $product = catalogProductBrowserDraft(catalogProductBrowserFresh());
    $page = catalogProductBrowser([P::PRODUCT_VIEW], 'ar');
    $page->resize(375, 812);
    $page->navigate("/admin/products/{$product}", BROWSER_PAGE_LOAD);

    $page->assertSee('التفاصيل');

    // Measured once the tab is in: it is fetched as it opens.
    expect(browserUntil($page, "document.querySelector('[data-test=\"details-name-ar\"]') !== null"))->toBeTrue()
        ->and($page->script('document.documentElement.dir'))->toBe('rtl')
        ->and($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();

    $page->navigate('/admin/products', BROWSER_PAGE_LOAD);

    expect(browserUntil($page, "document.querySelector('[data-test=\"product-search\"]') !== null"))->toBeTrue()
        ->and($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();
    $page->assertNoJavaScriptErrors();
});
