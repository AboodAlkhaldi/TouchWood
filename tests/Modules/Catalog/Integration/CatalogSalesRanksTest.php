<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Catalog\Application\Listing\ListingRows;
use Modules\Catalog\Public\Contracts\ListingFacts;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;

use function Pest\Laravel\seed;

/*
| The shop's Best-Selling ranks (catalog.md amendment 30; sales.md §1.13): each store's whole ranking pushed
| in by Sales and kept in Catalog's own table — the listing's rows of the store take it at once, a row
| rewritten later takes it again from the table, and a product left out of a ranking has none there.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

/**
 * A ready product chosen in the stores.
 *
 * @param  list<string>  $stores
 */
function rankedProduct(array $stores = ['sa']): string
{
    $id = Px::ready()['product'];

    foreach ($stores as $store) {
        Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId($store), $id, true)));
    }

    return $id;
}

/** @return list<?int> the product's rank in each language's row of the store */
function rankedRows(string $productId, string $store = 'sa'): array
{
    return array_values(DB::table('catalog.listing')->where('store_id', Fx::storeId($store))->where('product_id', $productId)->orderBy('locale')
        ->pluck('sales_rank')->map(fn ($rank): ?int => $rank === null ? null : (int) $rank)->all());
}

/**
 * @param  array<string, int>  $ranks
 */
function rankedPush(array $ranks, string $store = 'sa'): void
{
    app(ListingFacts::class)->salesRanks(StoreId::fromString(Fx::storeId($store)), $ranks);
}

it('gives the store listing rows their ranks, and leaves the other stores as they were', function () {
    $first = rankedProduct(['sa', 'eg']);
    $second = rankedProduct(['sa', 'eg']);

    rankedPush([$first => 2, strtoupper($second) => 1]);

    expect(rankedRows($first))->toBe([2, 2])
        ->and(rankedRows($second))->toBe([1, 1])
        ->and(rankedRows($first, 'eg'))->toBe([null, null])
        ->and(DB::table('catalog.sales_ranks')->count())->toBe(2);
});

it('replaces the store whole ranking: a product left out has no rank there now', function () {
    $first = rankedProduct();
    $second = rankedProduct();
    rankedPush([$first => 1, $second => 2]);

    rankedPush([$second => 1]);

    expect(rankedRows($first))->toBe([null, null])
        ->and(rankedRows($second))->toBe([1, 1]);

    rankedPush([]);

    expect(rankedRows($second))->toBe([null, null])
        ->and(DB::table('catalog.sales_ranks')->count())->toBe(0);
});

it('keeps the rank through a rewrite of the listing row, which takes it from the ranks table', function () {
    $product = rankedProduct();
    rankedPush([$product => 3]);

    DB::table('catalog.listing')->where('product_id', $product)->delete();
    DB::transaction(fn () => app(ListingRows::class)->refresh([$product]));

    expect(rankedRows($product))->toBe([3, 3]);
});

it('takes no unknown product, malformed id or rank below 1', function () {
    $product = rankedProduct();

    rankedPush([strtolower((string) Str::ulid()) => 1, 'not-a-product' => 2, $product => 0]);

    expect(DB::table('catalog.sales_ranks')->count())->toBe(0)
        ->and(rankedRows($product))->toBe([null, null])
        ->and(fn () => DB::table('catalog.sales_ranks')->insert(['store_id' => Fx::storeId('sa'), 'product_id' => $product, 'rank' => 0, 'updated_at' => now()]))
        ->toThrow(QueryException::class, '"catalog_sales_ranks_rank"');
});
