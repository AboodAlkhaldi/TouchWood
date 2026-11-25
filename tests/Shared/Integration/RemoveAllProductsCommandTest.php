<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\PendingCommand;
use Tests\Modules\B2B\Support\B2BFixtures as Bx;
use Tests\Modules\Feedback\Support\FeedbackFixtures as Fbx;
use Tests\Modules\Sales\Support\CheckoutFixtures as Cx;

use function Pest\Laravel\artisan;
use function Pest\Laravel\seed;

/*
| The one-off `touchwood:remove-all-products` (catalogue reload, owner 2026-11-25): every product goes with what hangs on
| it - sizes, prices, stock, cart lines, reviews - while brands and categories stay; an order, or a wrong confirmation,
| deletes nothing.
|
| Every helper here is named after this file's subject: a Pest file's functions are global.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

afterEach(fn () => File::deleteDirectory(Bx::uploads()));

/**
 * The command, its output checked (console output mocking is on in tests).
 *
 * @param  array<string, mixed>  $options
 */
function removeAllProductsRun(array $options = []): PendingCommand
{
    $pending = artisan('touchwood:remove-all-products', $options);

    if (! $pending instanceof PendingCommand) {
        throw new LogicException('Console output mocking is off, so the command cannot be checked.');
    }

    return $pending;
}

/** @return array<string, int> the rows that hang on products */
function removeAllProductsCounts(): array
{
    return array_map(static fn (string $table): int => DB::table($table)->count(), [
        'products' => 'catalog.products', 'variants' => 'catalog.variants', 'prices' => 'pricing.retail_prices',
        'stock' => 'inventory.stock', 'cart' => 'sales.cart_lines', 'reviews' => 'feedback.reviews', 'listing' => 'catalog.listing',
    ]);
}

it('deletes every product with its sizes, prices, stock, cart lines and reviews, and keeps brands and categories', function () {
    $ready = Cx::ready();
    Fbx::reviewRow($ready['product'], $ready['variants'][0], $ready['customer']);
    $brands = DB::table('catalog.brands')->count();
    $categories = DB::table('catalog.categories')->count();
    expect(removeAllProductsCounts())->each->toBeGreaterThan(0);

    removeAllProductsRun(['--yes' => true])->assertExitCode(0)->run();

    expect(removeAllProductsCounts())->each->toBe(0)
        ->and(DB::table('catalog.brands')->count())->toBe($brands)
        ->and(DB::table('catalog.categories')->count())->toBe($categories);
});

it('refuses while the shop has an order, and deletes nothing', function () {
    $ready = Cx::ready();
    Cx::place(Cx::start($ready['address'])->id);
    $before = removeAllProductsCounts();

    removeAllProductsRun(['--yes' => true])->expectsOutputToContain('Refused: the shop has orders')->assertExitCode(1)->run();

    expect(removeAllProductsCounts())->toBe($before);
});

it('deletes nothing unless the words are typed back exactly', function () {
    Cx::ready();
    $before = removeAllProductsCounts();

    removeAllProductsRun()
        ->expectsQuestion('Type REMOVE ALL PRODUCTS to delete every product for good', 'remove all products')
        ->assertExitCode(1)->run();

    expect(removeAllProductsCounts())->toBe($before);
});
