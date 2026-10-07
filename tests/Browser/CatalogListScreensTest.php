<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Application\CatalogPermissions as P;
use Modules\Catalog\Application\Command\AddBrand\AddBrand;
use Modules\Catalog\Application\Command\AddBrand\AddBrandHandler;
use Modules\Catalog\Application\Command\AddCategory\AddCategory;
use Modules\Catalog\Application\Command\AddCategory\AddCategoryHandler;
use Modules\Catalog\Application\Command\AddWordPair\AddWordPair;
use Modules\Catalog\Application\Command\AddWordPair\AddWordPairHandler;
use Modules\Catalog\Application\Command\CreateProduct\CreateProduct;
use Modules\Catalog\Application\Command\CreateProduct\CreateProductHandler;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\seed;

/*
| Catalog's list screens, in a real browser (catalog.md §4.4 S1–S7, amendment 13).
|
| The feature tests beside them prove what each screen is handed and what the server does with what
| it sends. These prove a person can use them: that each dialog draws, takes what is typed and saves
| it, that deactivating a brand reads the products it reaches before asking for their fate, and that
| a page reads right to left in Arabic at a phone's width.
|
| No RefreshDatabase, for the reason written at the top of StaffScreensTest: every name here is made
| unique, as the rows stay. A photo is not chosen here - the plugin's server receives no file (lesson
| 117); uploading one is the feature tests' (CatalogListScreensTest).
*/

beforeEach(function () {
    // The suite serves the application in this process, which inherits phpunit.xml's "array"
    // session driver - one that keeps nothing between requests.
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

/**
 * Signed in to the panel, through its real screens, as someone holding these Catalog jobs with All
 * stores, reading the panel in this language.
 *
 * @param  list<string>  $permissions
 */
function catalogListBrowser(array $permissions, string $locale = 'en'): mixed
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

/**
 * What a read gives once a save has landed: a click only sends the form, so it is read again for
 * up to five seconds until it holds. Waited through the page, never `usleep()`: the application is
 * served by this same process, which must keep serving while the test waits (MediaAndAuditScreenTest).
 */
function catalogListBrowserSoon(mixed $page, Closure $read, Closure $holds): mixed
{
    $value = $read();

    for ($tries = 0; $tries < 50 && ! $holds($value); $tries++) {
        $page->wait(0.1);
        $value = $read();
    }

    return $value;
}

/**
 * A number for names that must be new: the rows of earlier runs stay, and an Arabic name keeps only
 * its letters and digits for its address, so two names apart only in Latin letters would collide.
 * Read from the clock, so two calls - and two runs - never give the same.
 */
function catalogListBrowserFresh(): string
{
    return substr((string) hrtime(true), -12);
}

function catalogListBrowserBrand(string $n, string $nameEn): string
{
    return Fx::asSystem(fn (): string => app(AddBrandHandler::class)->handle(new AddBrand("ماركة {$n}", "{$nameEn} {$n}", 'DISTRIBUTOR')));
}

it('adds a brand from its dialog, and deactivates another after reading the products it reaches', function () {
    $n = catalogListBrowserFresh();
    // Two brands, each its own number: the first one ever made is the default, which never goes (§1.6).
    catalogListBrowserBrand(catalogListBrowserFresh(), 'Keep');
    $brand = catalogListBrowserBrand(catalogListBrowserFresh(), 'Leaving');
    $product = Fx::asSystem(fn (): string => app(CreateProductHandler::class)->handle(new CreateProduct("درج {$n}", "Drawer {$n}", $brand)));

    $page = catalogListBrowser([P::BRAND_MANAGE]);
    $page->navigate('/admin/brands', BROWSER_PAGE_LOAD);
    $page->assertSee('Brands');

    $page->click('[data-test="add-brand"]')
        ->type('#brand-name-ar', "هيتش {$n}")
        ->type('#brand-name-en', "Hettich {$n}")
        ->select('#brand-agency', 'EXCLUSIVE_AGENT')
        ->type('#brand-position', '40')
        ->click('[data-test="confirm-brand"]');

    $added = catalogListBrowserSoon(
        $page,
        fn () => DB::table('catalog.brands')->where('name_en', "Hettich {$n}")->first(['agency_type', 'position']),
        fn ($row): bool => $row !== null,
    );

    expect($added)->toEqual((object) ['agency_type' => 'EXCLUSIVE_AGENT', 'position' => 40]);

    // The fates dialog reads the brand's products when it opens, and says how many before its button.
    $page->click("[data-test=\"brand-actions-{$brand}\"]")
        ->click('[data-test="deactivate-brand"]');

    expect(browserUntil($page, 'document.querySelector(\'[data-test="reached-count"]\')?.textContent === "Products it reaches: 1."'))->toBeTrue();
    $page->assertSee('cannot be ordered');

    $page->click('[data-test="confirm-deactivate"]');

    $state = catalogListBrowserSoon(
        $page,
        fn () => DB::table('catalog.brands')->where('id', $brand)->value('is_active'),
        fn ($active): bool => $active === false,
    );

    expect($state)->toBeFalse()
        ->and(DB::table('catalog.products')->where('id', $product)->value('hidden_by_brand'))->toBeTrue();

    $page->assertNoJavaScriptErrors();
});

it('shows the tree for the store chosen, folds and opens a branch, and adds a category under another', function () {
    $n = catalogListBrowserFresh();
    $top = Fx::asSystem(fn (): string => app(AddCategoryHandler::class)->handle(new AddCategory("مطابخ {$n}", "Kitchens {$n}")));
    $child = Fx::asSystem(fn (): string => app(AddCategoryHandler::class)->handle(new AddCategory("أدراج {$n}", "Drawers {$n}", $top)));

    $page = catalogListBrowser([P::CATEGORY_MANAGE, P::CATEGORY_RANK]);
    $page->navigate('/admin/categories?store=eg', BROWSER_PAGE_LOAD);
    $page->assertSee('Categories');

    // The first level opens with the page; its button folds a branch and opens it again.
    expect($page->script('document.querySelector("[data-test=\"store-filter\"]").selectedOptions[0].textContent'))->toBe('Egypt')
        ->and($page->script("document.querySelector('[data-test=\"category-{$child}\"]') !== null"))->toBeTrue();

    $page->click("[data-test=\"toggle-{$top}\"]");

    expect(browserUntil($page, "document.querySelector('[data-test=\"category-{$child}\"]') === null"))->toBeTrue()
        ->and($page->script("document.querySelector('[data-test=\"toggle-{$top}\"]').getAttribute('aria-expanded')"))->toBe('false');

    $page->click("[data-test=\"toggle-{$top}\"]");

    expect(browserUntil($page, "document.querySelector('[data-test=\"category-{$child}\"]') !== null"))->toBeTrue();

    $page->click('[data-test="add-category"]')
        ->type('#category-name-ar', "مقابض {$n}")
        ->type('#category-name-en', "Handles {$n}")
        ->select('#category-parent', $top)
        ->click('[data-test="confirm-add-category"]');

    $parent = catalogListBrowserSoon(
        $page,
        fn () => DB::table('catalog.categories')->where('name_en', "Handles {$n}")->value('parent_id'),
        fn ($id): bool => $id !== null,
    );

    expect($parent)->toBe($top);
    $page->assertNoJavaScriptErrors();
});

it('adds a colour attribute, opens it, and gives it a value with its swatch', function () {
    $n = catalogListBrowserFresh();
    $page = catalogListBrowser([P::ATTRIBUTE_MANAGE]);
    $page->navigate('/admin/attributes', BROWSER_PAGE_LOAD);
    $page->assertSee('Attributes');

    $page->click('[data-test="add-attribute"]')
        ->type('#attribute-name-ar', "لون {$n}")
        ->type('#attribute-name-en', "Colour {$n}")
        ->select('#attribute-kind', 'VARIANT')
        ->click('#attribute-colour')
        ->click('[data-test="confirm-attribute"]');

    $attribute = catalogListBrowserSoon(
        $page,
        fn () => DB::table('catalog.attributes')->where('name_en', "Colour {$n}")->first(['id', 'is_colour']),
        fn ($row): bool => $row !== null,
    );

    expect($attribute?->is_colour)->toBeTrue();

    $page->navigate("/admin/attributes/{$attribute?->id}", BROWSER_PAGE_LOAD);
    $page->click('[data-test="add-value"]')
        ->type('#value-name-ar', "أزرق {$n}")
        ->type('#value-name-en', "Blue {$n}")
        ->type('#value-swatch', '#336699')
        ->click('[data-test="confirm-value"]');

    $swatch = catalogListBrowserSoon(
        $page,
        fn () => DB::table('catalog.attribute_values')->where('name_en', "Blue {$n}")->value('swatch'),
        fn ($value): bool => $value !== null,
    );

    expect($swatch)->toBe('#336699')
        ->and(browserUntil($page, "document.body.innerText.includes('Blue {$n}')"))->toBeTrue();

    $page->assertNoJavaScriptErrors();
});

it('adds a label by its meaning and strength, shown as it will look', function () {
    $n = catalogListBrowserFresh();
    $page = catalogListBrowser([P::LABEL_MANAGE]);
    $page->navigate('/admin/labels', BROWSER_PAGE_LOAD);

    $page->click('[data-test="add-label"]')
        ->type('#label-name-ar', "عرض {$n}")
        ->type('#label-name-en', "Offer {$n}")
        ->click('#meaning-warning')
        ->click('#strength-subtle');

    expect(browserUntil($page, "document.querySelector('[data-test=\"label-preview\"]')?.textContent.includes('Offer {$n}')"))->toBeTrue();

    $page->click('[data-test="confirm-label"]');

    $tone = catalogListBrowserSoon(
        $page,
        fn () => DB::table('catalog.labels')->where('name_en', "Offer {$n}")->value('tone'),
        fn ($value): bool => $value !== null,
    );

    expect($tone)->toBe('amber-subtle');
    $page->assertNoJavaScriptErrors();
});

it('adds a warranty for life, with its terms as typed', function () {
    $n = catalogListBrowserFresh();
    $page = catalogListBrowser([P::WARRANTY_MANAGE]);
    $page->navigate('/admin/warranties', BROWSER_PAGE_LOAD);

    $page->click('[data-test="add-warranty"]')
        ->type('#warranty-name-ar', "ضمان {$n}")
        ->type('#warranty-name-en', "Lifetime {$n}")
        ->click('#warranty-lifetime')
        ->type('#warranty-terms-ar', 'يشمل المفصلات.')
        ->type('#warranty-terms-en', 'Covers the hinges.')
        ->click('[data-test="confirm-warranty"]');

    $row = catalogListBrowserSoon(
        $page,
        fn () => DB::table('catalog.warranties')->where('name_en', "Lifetime {$n}")->first(['period_months', 'terms_en']),
        fn ($value): bool => $value !== null,
    );

    expect($row?->period_months)->toBeNull()
        ->and((string) $row?->terms_en)->toContain('Covers the hinges.');
    $page->assertNoJavaScriptErrors();
});

it('adds a word pair and deletes another', function () {
    $n = catalogListBrowserFresh();
    $old = Fx::asSystem(fn (): string => app(AddWordPairHandler::class)->handle(new AddWordPair("rail{$n}", "runner{$n}")));
    $page = catalogListBrowser([P::SEARCH_WORD_MANAGE]);
    $page->navigate('/admin/search-words', BROWSER_PAGE_LOAD);
    $page->assertSee('Search Words');

    $page->click('[data-test="add-pair"]')
        ->type('#pair-word-a', "hinge{$n}")
        ->type('#pair-word-b', "مفصلة{$n}")
        ->click('[data-test="confirm-pair"]');

    $added = catalogListBrowserSoon(
        $page,
        fn () => DB::table('catalog.word_pairs')->where('word_a', "hinge{$n}")->orWhere('word_b', "hinge{$n}")->exists(),
        fn ($value): bool => $value === true,
    );

    expect($added)->toBeTrue();

    $page->click("[data-test=\"pair-{$old}\"] [data-test=\"delete-pair\"]")
        ->click('[data-test="confirm-delete-pair"]');

    $gone = catalogListBrowserSoon(
        $page,
        fn () => DB::table('catalog.word_pairs')->where('id', $old)->exists(),
        fn ($value): bool => $value === false,
    );

    expect($gone)->toBeFalse();
    $page->assertNoJavaScriptErrors();
});

it('reads right to left in Arabic, at a phone\'s width, with nothing wider than the screen', function () {
    $page = catalogListBrowser([P::LABEL_MANAGE], 'ar');
    // Signed in at desktop size first: a phone-sized sign-in types before React takes the form over
    // (lesson 118).
    $page->resize(375, 812);
    $page->navigate('/admin/labels', BROWSER_PAGE_LOAD);

    $page->assertSee('الشارات');

    expect($page->script('document.documentElement.dir'))->toBe('rtl')
        ->and($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();

    $page->assertNoJavaScriptErrors();
});
