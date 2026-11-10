<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Catalog\Application\Command\DeactivateBrand\DeactivateBrand;
use Modules\Catalog\Application\Command\DeactivateBrand\DeactivateBrandHandler;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Dto\SitemapEntryDto;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;

use function Pest\Laravel\seed;

/*
| CatalogApi::sitemapEntries (catalog.md amendment 41(b); content.md §2.3 row C3): what a guest finds listed in a
| store - products orderable and open to them, the categories of their menu, the active brands shown by default -
| with both current slugs and the last change, keyset-paged.
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
    // Chosen here, with no stock: not orderable now (catalog.md §1.4, amendment 39).
    $soldOut = sitemapEntriesProduct(Px::category('Hinges'), ['sa'], 0);
    // Ready, never chosen in any store.
    $unchosen = Px::ready(['60 cm'], Px::category('Locks'));

    $keys = sitemapEntriesKeys('sa');

    $soldOutCategory = (string) DB::table('catalog.products')->where('id', $soldOut['product'])->value('category_id');

    expect($keys)->toContain("product:{$listed['product']}", "category:{$category}");
    expect(array_intersect($keys, ["product:{$elsewhere['product']}", "product:{$soldOut['product']}", "product:{$unchosen['product']}", "category:{$soldOutCategory}"]))->toBe([]);
    expect(sitemapEntriesKeys('ae'))->toContain("product:{$elsewhere['product']}");
    expect(in_array("product:{$listed['product']}", sitemapEntriesKeys('ae'), true))->toBeFalse();
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
