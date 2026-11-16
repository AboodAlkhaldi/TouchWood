<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Catalog\Application\Query\Shop\CategoryPage;
use Modules\Catalog\Application\Query\Shop\ShopCatalog;
use Modules\Catalog\Application\Query\Shop\ShopFilters;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Platform\Public\Enums\ShopperKind;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Pricing\Support\PricingFixtures as Rx;

use function Pest\Laravel\seed;

/*
| A category page's head and its price sorts (shop-design.md §5.1, §5.3, C8): the description under its name, how many
| products the chosen filters show across every page («Showing 1–24 of 918»), and the cards by this viewer's card price -
| the least a size costs, "From" where sizes differ - lowest or highest first, walked page by page with no product
| skipped or repeated.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

/**
 * A product in Saudi Arabia in the category, its sizes at the prices given.
 *
 * @param  list<string>  $prices
 */
function headProduct(string $category, array $prices): string
{
    $p = Px::ready(array_map(static fn (int $n): string => (60 + 20 * $n).' cm', array_keys($prices)), $category);
    Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId('sa'), $p['product'], true)));
    Px::onSale('sa', $p['product']);
    Rx::set('sa', array_combine($p['variants'], $prices));

    return $p['product'];
}

/**
 * @throws InvalidCatalogAttribute
 */
function headPage(string $category, ?ShopFilters $filters = null, ?string $after = null, int $limit = 24): CategoryPage
{
    $slug = (string) DB::table('catalog.category_slugs')->where('category_id', $category)->where('locale', 'en')->where('is_current', true)->value('slug');
    $page = app(ShopCatalog::class)->category(StoreId::fromString(Fx::storeId('sa')), 'en', ShopperKind::Individual, $slug, $filters, $after, $limit);
    expect($page)->toBeInstanceOf(CategoryPage::class);

    /** @var CategoryPage $page */
    return $page;
}

/**
 * @return list<string>
 */
function headIds(CategoryPage $page): array
{
    return array_map(static fn ($card): string => $card->productId, $page->products->cards);
}

it('sorts by the card price both ways, a product of several sizes by its least', function () {
    $category = Px::category('Kitchens');
    $twenty = headProduct($category, ['20.00']);
    $ten = headProduct($category, ['10.00']);
    // Two sizes: "From 15.00".
    $fifteen = headProduct($category, ['40.00', '15.00']);
    $thirty = headProduct($category, ['30.00']);

    expect(headIds(headPage($category, ShopFilters::of(sort: ShopFilters::PRICE_LOW))))->toBe([$ten, $fifteen, $twenty, $thirty])
        ->and(headIds(headPage($category, ShopFilters::of(sort: ShopFilters::PRICE_HIGH))))->toBe([$thirty, $twenty, $fifteen, $ten]);
});

it('walks a price sort page by page by the same order, ties newest first, never skipping or repeating a product', function (string $sort) {
    $category = Px::category('Kitchens');

    foreach (['20.00', '10.00', '20.00', '15.00', '10.00'] as $price) {
        headProduct($category, [$price]);
    }

    $whole = headIds(headPage($category, ShopFilters::of(sort: $sort)));
    $walked = [];
    $after = null;

    do {
        $page = headPage($category, ShopFilters::of(sort: $sort), $after, 2);
        array_push($walked, ...headIds($page));
        $after = $page->products->next;
    } while ($after !== null);

    expect($walked)->toBe($whole)->and($whole)->toHaveCount(5);
})->with([ShopFilters::PRICE_LOW, ShopFilters::PRICE_HIGH]);

it('refuses a sort it does not know', function () {
    ShopFilters::of(sort: 'price');
})->throws(InvalidCatalogAttribute::class);

it('gives the head its description and how many products the chosen filters show, every page of them', function () {
    $category = Px::category('Kitchens');
    $first = headProduct($category, ['10.00']);

    foreach (range(1, 3) as $n) {
        headProduct($category, ['20.00']);
    }

    DB::table('catalog.categories')->where('id', $category)->update(['description_en' => 'Cabinets, doors and fittings.']);
    $page = headPage($category, null, null, 2);

    expect($page->description)->toBe('Cabinets, doors and fittings.')
        // Four in all, though this page shows two.
        ->and($page->total)->toBe(4)
        ->and($page->products->cards)->toHaveCount(2);

    // Narrowed to the first product's brand: as many as carry it.
    $brand = (string) DB::table('catalog.products')->where('id', $first)->value('brand_id');
    $ofBrand = DB::table('catalog.products')->whereIn('id', DB::table('catalog.listing')->where('store_id', Fx::storeId('sa'))->where('locale', 'en')->pluck('product_id'))->where('brand_id', $brand)->count();
    expect(headPage($category, ShopFilters::of(brands: [$brand]))->total)->toBe($ofBrand);
});
