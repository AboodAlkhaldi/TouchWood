<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Catalog\Application\Command\DeactivateBrand\DeactivateBrand;
use Modules\Catalog\Application\Command\DeactivateBrand\DeactivateBrandHandler;
use Modules\Catalog\Application\Command\MarkNotAvailableNow\MarkNotAvailableNow;
use Modules\Catalog\Application\Command\MarkNotAvailableNow\MarkNotAvailableNowHandler;
use Modules\Catalog\Application\Command\SetCategoryAudiences\SetCategoryAudiences;
use Modules\Catalog\Application\Command\SetCategoryAudiences\SetCategoryAudiencesHandler;
use Modules\Catalog\Application\Command\SetSellingTerms\SetSellingTerms;
use Modules\Catalog\Application\Command\SetSellingTerms\SetSellingTermsHandler;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Dto\SitemapEntryDto;
use Modules\Inventory\Application\Command\SetStock\SetStock;
use Modules\Inventory\Application\Command\SetStock\SetStockHandler;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;

use function Pest\Laravel\get;
use function Pest\Laravel\seed;

/*
| CatalogApi::sitemapEntries (catalog.md amendment 41(b); content.md §2.3 row C3): what a guest finds listed in a
| store - products orderable and open to them, the categories of their menu, the active brands shown by default -
| and what is only sold out or unpriced there, which a guest would see were it in stock and priced, with the
| categories it emptied (owner, 2026-11-10: their pages answer with no noindex, amendment 39(i), (j)); with both
| current slugs and the last change, keyset-paged.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

/**
 * A ready product in this category, chosen and on sale in these stores.
 *
 * @param  list<string>  $stores
 * @return array{product: string, variants: list<string>, width: string}
 */
function sitemapEntriesProduct(string $categoryId, array $stores = ['sa'], int $pieces = 5): array
{
    $p = Px::ready(['60 cm'], $categoryId);

    foreach ($stores as $store) {
        Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId($store), $p['product'], true)));
        Px::onSale($store, $p['product'], $pieces);
    }

    return $p;
}

/**
 * Every entry of the store, page by page.
 *
 * @return list<SitemapEntryDto>
 */
function sitemapEntriesAll(string $store, int $limit = 1000): array
{
    $entries = [];
    $after = null;

    // Bounded: no store here holds more than a few dozen entries (lesson 194).
    for ($page = 0; $page < 100; $page++) {
        $found = app(CatalogApi::class)->sitemapEntries(StoreId::fromString(Fx::storeId($store)), $after, $limit);
        $entries = [...$entries, ...$found->entries];
        $after = $found->next;

        if ($after === null) {
            return $entries;
        }
    }

    throw new LogicException('The pages never ended.');
}

/** @return list<string> "kind:id" of each entry */
function sitemapEntriesKeys(string $store): array
{
    return array_map(static fn (SitemapEntryDto $entry): string => "{$entry->kind}:{$entry->id}", sitemapEntriesAll($store));
}

function sitemapEntriesSlug(string $kind, string $id, string $locale): string
{
    return (string) DB::table("catalog.{$kind}_slugs")->where("{$kind}_id", $id)->where('locale', $locale)->where('is_current', true)->value('slug');
}

it('lists a listed product with its category and brand, each with both current slugs and its last change', function () {
    $category = Px::category('Kitchens');
    $p = sitemapEntriesProduct($category);
    $brand = (string) DB::table('catalog.products')->where('id', $p['product'])->value('brand_id');

    $entries = sitemapEntriesAll('sa');
    $byKey = [];

    foreach ($entries as $entry) {
        $byKey["{$entry->kind}:{$entry->id}"] = $entry;
    }

    // Categories first, then brands, then products.
    expect(array_keys($byKey))->toBe(["category:{$category}", "brand:{$brand}", "product:{$p['product']}"]);

    foreach (['category' => $category, 'brand' => $brand, 'product' => $p['product']] as $kind => $id) {
        $entry = $byKey["{$kind}:{$id}"];
        $changed = (string) DB::table($kind === 'category' ? 'catalog.categories' : "catalog.{$kind}s")->where('id', $id)->value('updated_at');

        expect($entry->slugAr)->toBe(sitemapEntriesSlug($kind, $id, 'ar'))
            ->and($entry->slugEn)->toBe(sitemapEntriesSlug($kind, $id, 'en'))
            ->and($entry->slugAr)->not->toBe('')
            ->and($entry->changedAt->getTimestamp())->toBe((new DateTimeImmutable($changed))->getTimestamp());
    }
});

it('leaves out what a guest does not find listed in the store', function () {
    $category = Px::category('Kitchens');
    $listed = sitemapEntriesProduct($category);
    // Chosen in another store only.
    $elsewhere = sitemapEntriesProduct(Px::category('Doors'), ['ae']);
    // Ready, never chosen in any store.
    $unchosen = Px::ready(['60 cm'], Px::category('Locks'));
    // Marked "Not available now" by staff: its page keeps noindex (amendment 39(i)).
    $markedCategory = Px::category('Hinges');
    $marked = sitemapEntriesProduct($markedCategory);
    Fx::asSystem(fn () => app(MarkNotAvailableNowHandler::class)->handle(new MarkNotAvailableNow(Fx::storeId('sa'), $marked['product'])));
    // Sold out in a category switched off for both kinds of account: no viewer would see it in stock either.
    $closedCategory = Px::category('Handles');
    $closed = sitemapEntriesProduct($closedCategory);
    Fx::asSystem(fn () => app(SetCategoryAudiencesHandler::class)->handle(new SetCategoryAudiences(Fx::storeId('sa'), $closedCategory, false, false)));
    Px::onSale('sa', $closed['product'], 0);
    // Sold out, sold wholesale only, in a category switched off for companies: an individual never saw it.
    $wholesaleCategory = Px::category('Doors');
    $wholesale = sitemapEntriesProduct($wholesaleCategory);
    Fx::asSystem(fn () => app(SetSellingTermsHandler::class)->handle(new SetSellingTerms(Fx::storeId('sa'), $wholesale['product'], [$wholesale['variants'][0] => ['retail' => false, 'wholesale' => true]], wholesaleMinimum: 5)));
    Fx::asSystem(fn () => app(SetCategoryAudiencesHandler::class)->handle(new SetCategoryAudiences(Fx::storeId('sa'), $wholesaleCategory, null, false)));
    Px::onSale('sa', $wholesale['product'], 0);

    $keys = sitemapEntriesKeys('sa');

    expect($keys)->toContain("product:{$listed['product']}", "category:{$category}");
    expect(array_intersect($keys, [
        "product:{$elsewhere['product']}", "product:{$unchosen['product']}", "product:{$marked['product']}", "category:{$markedCategory}",
        "product:{$closed['product']}", "category:{$closedCategory}", "product:{$wholesale['product']}", "category:{$wholesaleCategory}",
    ]))->toBe([]);
    expect(sitemapEntriesKeys('ae'))->toContain("product:{$elsewhere['product']}");
    expect(in_array("product:{$listed['product']}", sitemapEntriesKeys('ae'), true))->toBeFalse();
});

it('keeps a product only sold out and the category it emptied, whose pages answer with no noindex (owner, 2026-11-10)', function () {
    $top = Px::category('Kitchens');
    $emptied = Px::category('Runners', $top);
    $gone = sitemapEntriesProduct($emptied);
    // Sold out after it was on sale: its listing row stays, not orderable (amendment 39(b)).
    Px::onSale('sa', $gone['product'], 0);
    expect(DB::table('catalog.listing')->where('product_id', $gone['product'])->where('orderable', true)->exists())->toBeFalse();

    expect(sitemapEntriesKeys('sa'))->toContain("product:{$gone['product']}", "category:{$emptied}", "category:{$top}");

    // As a guest finds them: each page answers, with no noindex (amendment 39(i), (j)).
    foreach (['products' => sitemapEntriesSlug('product', $gone['product'], 'en'), 'categories' => sitemapEntriesSlug('category', $emptied, 'en')] as $path => $slug) {
        app()->forgetScopedInstances();
        get("/sa/en/{$path}/{$slug}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('address.noindex', false));
    }

    // Back in stock, it is listed as before; the entries are the same.
    $before = sitemapEntriesKeys('sa');
    Px::onSale('sa', $gone['product'], 5);
    expect(sitemapEntriesKeys('sa'))->toBe($before);
});

it('keeps a product a guest would see were its retail size in stock, though only its wholesale size is left', function () {
    // Off for companies here: a guest sees what an individual sees, so the retail size sold out leaves it unlisted.
    $category = Px::category('Doors');
    $p = Px::ready(['60 cm', '80 cm'], $category);
    Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId('sa'), $p['product'], true)));
    Px::onSale('sa', $p['product']);
    [$retail, $wholesale] = $p['variants'];
    Fx::asSystem(fn () => app(SetSellingTermsHandler::class)->handle(new SetSellingTerms(Fx::storeId('sa'), $p['product'], [$retail => ['retail' => true, 'wholesale' => false], $wholesale => ['retail' => false, 'wholesale' => true]], wholesaleMinimum: 5)));
    Fx::asSystem(fn () => app(SetCategoryAudiencesHandler::class)->handle(new SetCategoryAudiences(Fx::storeId('sa'), $category, null, false)));
    Fx::asSystem(fn () => app(SetStockHandler::class)->handle(new SetStock(Fx::storeId('sa'), [$retail => 0])));

    // Still orderable (the wholesale size), but listed for neither kind of account here.
    expect(DB::table('catalog.listing')->where('product_id', $p['product'])->where('orderable', true)->where(fn ($q) => $q->where('for_individuals', true)->orWhere('for_companies', true))->exists())->toBeFalse()
        ->and(DB::table('catalog.listing')->where('product_id', $p['product'])->where('orderable', true)->exists())->toBeTrue();

    expect(sitemapEntriesKeys('sa'))->toContain("product:{$p['product']}", "category:{$category}");
    app()->forgetScopedInstances();
    get('/sa/en/products/'.sitemapEntriesSlug('product', $p['product'], 'en'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('available', false)->where('address.noindex', false));
});

it('leaves out a category or brand that is off, and the products hidden with it', function () {
    $category = Px::category('Kitchens');
    $p = sitemapEntriesProduct($category);
    $brand = (string) DB::table('catalog.products')->where('id', $p['product'])->value('brand_id');
    expect(sitemapEntriesKeys('sa'))->toContain("brand:{$brand}");

    Fx::asSystem(fn () => app(DeactivateBrandHandler::class)->handle(new DeactivateBrand($brand, 'HIDE')));

    expect(array_intersect(sitemapEntriesKeys('sa'), ["brand:{$brand}", "product:{$p['product']}"]))->toBe([]);
});

it('leaves out a brand that is not shown in the default listings, keeping its products', function () {
    $category = Px::category('Kitchens');
    $p = sitemapEntriesProduct($category);
    $brand = (string) DB::table('catalog.products')->where('id', $p['product'])->value('brand_id');

    // The listing copies the brand's flag (handoff §9.4); set it as a secondary brand's would be.
    DB::table('catalog.listing')->where('brand_id', $brand)->update(['brand_visible_by_default' => false]);

    expect(sitemapEntriesKeys('sa'))->not->toContain("brand:{$brand}")->toContain("product:{$p['product']}");
});

it('pages by a cursor, every entry once, the last page with no next', function () {
    $category = Px::category('Kitchens');
    sitemapEntriesProduct($category);
    sitemapEntriesProduct($category);
    sitemapEntriesProduct(Px::category('Doors'));

    $whole = array_map(static fn (SitemapEntryDto $entry): string => "{$entry->kind}:{$entry->id}", sitemapEntriesAll('sa'));
    $paged = array_map(static fn (SitemapEntryDto $entry): string => "{$entry->kind}:{$entry->id}", sitemapEntriesAll('sa', 1));

    expect($whole)->toHaveCount(count(array_unique($whole)))
        ->and(count($whole))->toBeGreaterThanOrEqual(7)
        ->and($paged)->toBe($whole);

    $first = app(CatalogApi::class)->sitemapEntries(StoreId::fromString(Fx::storeId('sa')), null, 2);
    expect($first->entries)->toHaveCount(2)->and($first->next)->toBe('1:'.$first->entries[1]->id);

    // A cursor that is not one starts from the first.
    expect(app(CatalogApi::class)->sitemapEntries(StoreId::fromString(Fx::storeId('sa')), 'nonsense', 2)->entries)->toEqual($first->entries);
});

it('reads a page in one query', function () {
    sitemapEntriesProduct(Px::category('Kitchens'));
    $store = StoreId::fromString(Fx::storeId('sa'));
    DB::flushQueryLog();
    DB::enableQueryLog();
    $found = app(CatalogApi::class)->sitemapEntries($store, null, 1000);
    DB::disableQueryLog();

    expect(DB::getQueryLog())->toHaveCount(1)->and($found->entries)->toHaveCount(3);
});
