<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Support\Facades\DB;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Catalog\Application\CatalogPermissions as P;
use Modules\Catalog\Application\Command\AddCategory\AddCategory;
use Modules\Catalog\Application\Command\AddCategory\AddCategoryHandler;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Access\Support\FakeBreachList;
use Tests\Modules\Access\Support\RecordingSecurityMessages;

use function Pest\Laravel\seed;

/*
| Who sees a category, on the categories screen, in a real browser (catalog.md §4.4 S2, amendment 14).
|
| The feature tests prove what the screen is handed and what the server does with a switch; these
| prove an admin can flip one - and that it reaches what is under it - and that the page reads right
| to left in Arabic at a phone's width with nothing wider than the screen.
|
| No RefreshDatabase, as in every browser test here: the rows stay, so every name is made new.
*/

beforeEach(function () {
    config(['session.driver' => 'database']);
    seed(PlatformSeeder::class);
    FakeBreachList::install();
    RecordingSecurityMessages::install();
});

/**
 * Signed in to the panel, through its real screens, as an admin holding these Catalog jobs with All
 * stores, reading the panel in this language.
 *
 * @param  list<string>  $permissions
 */
function catalogAudiencesBrowser(array $permissions, string $locale = 'en'): mixed
{
    $staffId = Fx::staffWith($permissions, ['*'], RoleLevel::Admin);
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

/** A number for names that must be new: read from the clock, so two calls and two runs never meet. */
function catalogAudiencesBrowserFresh(): string
{
    return substr((string) hrtime(true), -12);
}

function catalogAudiencesBrowserCategory(string $nameEn, ?string $parentId = null): string
{
    $n = catalogAudiencesBrowserFresh();

    return Fx::asSystem(fn (): string => app(AddCategoryHandler::class)->handle(new AddCategory("قسم {$n}", "{$nameEn} {$n}", $parentId)));
}

it('switches a category off for individuals in the store shown, reaching what is under it', function () {
    $top = catalogAudiencesBrowserCategory('Kitchens');
    $below = catalogAudiencesBrowserCategory('Drawers', $top);
    $store = Fx::storeId('sa');

    $page = catalogAudiencesBrowser([P::CATEGORY_AUDIENCE, P::CATEGORY_RANK]);
    $page->navigate('/admin/categories?store=sa', BROWSER_PAGE_LOAD);
    $page->assertSee('Categories');

    expect($page->script("document.querySelector('[data-test=\"audience-individuals-{$top}\"]').getAttribute('aria-checked')"))->toBe('true');

    $page->click("[data-test=\"audience-individuals-{$top}\"]");

    // The page comes back with every row as the server has it: the switch and the one under it off.
    expect(browserUntil($page, "document.querySelector('[data-test=\"audience-individuals-{$below}\"]')?.getAttribute('aria-checked') === 'false'"))->toBeTrue()
        ->and(DB::table('catalog.store_category_audiences')->where('store_id', $store)->whereIn('category_id', [$top, $below])->orderBy('category_id')->pluck('for_individuals')->all())->toBe([false, false])
        ->and($page->script("document.querySelector('[data-test=\"audience-individuals-{$top}\"]').getAttribute('aria-checked')"))->toBe('false')
        ->and($page->script("document.querySelector('[data-test=\"audience-companies-{$top}\"]').getAttribute('aria-checked')"))->toBe('true');

    $page->assertNoJavaScriptErrors();
});

it('shows the switches out of reach, with the reason, to someone who only orders the menu', function () {
    $category = catalogAudiencesBrowserCategory('Lighting');

    $page = catalogAudiencesBrowser([P::CATEGORY_RANK]);
    $page->navigate('/admin/categories?store=sa', BROWSER_PAGE_LOAD);
    $page->assertSee('Categories');

    // Out of reach, and the reason said to a screen reader with it (the tooltip shows the same words).
    expect($page->script("document.querySelector('[data-test=\"audience-individuals-{$category}\"]').getAttribute('aria-disabled')"))->toBe('true')
        ->and($page->script("document.getElementById(document.querySelector('[data-test=\"audience-individuals-{$category}\"]').getAttribute('aria-describedby')).textContent"))
        ->toBe('Choosing who sees categories in Saudi Arabia needs an admin role with that job.');

    // A pointer cannot press it (the browser treats it as disabled). A click sent to it anyway changes
    // nothing - the page ignores it, and the server would refuse it too (the feature test's), so this
    // proves only that nothing slips through.
    $page->script("document.querySelector('[data-test=\"audience-individuals-{$category}\"]').click()");
    $page->wait(0.5);

    expect(DB::table('catalog.store_category_audiences')->where('category_id', $category)->exists())->toBeFalse();
    $page->assertNoJavaScriptErrors();
});

it('reads right to left in Arabic, at a phone\'s width, with nothing wider than the screen', function () {
    catalogAudiencesBrowserCategory('Wardrobes');
    $page = catalogAudiencesBrowser([P::CATEGORY_AUDIENCE, P::CATEGORY_RANK, P::CATEGORY_MANAGE], 'ar');
    // Signed in at desktop size first: a phone-sized sign-in types before React takes the form over
    // (lesson 118).
    $page->resize(375, 812);
    $page->navigate('/admin/categories?store=sa', BROWSER_PAGE_LOAD);

    $page->assertSee('الأقسام')->assertSee('الأفراد')->assertSee('الشركات');

    expect($page->script('document.documentElement.dir'))->toBe('rtl')
        ->and($page->script('document.documentElement.scrollWidth <= window.innerWidth'))->toBeTrue();

    $page->assertNoJavaScriptErrors();
});
