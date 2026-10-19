<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Feedback\Support\FeedbackFixtures as Fb;

use function Pest\Laravel\seed;

/*
| The database's own refusals behind the wishlist's code (feedback.md §5; handoff §5.3): its key and
| each of its foreign keys, each row breaking that one rule only (lesson 112); a product deleted takes
| its items with it, a customer or a store is refused.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

/**
 * @param  array<string, mixed>  $with
 */
function wishlistTableRow(string $productId, string $customerId, array $with = []): void
{
    DB::table('feedback.wishlist_items')->insert([
        'customer_id' => $customerId, 'product_id' => $productId, 'store_id' => Fx::storeId('sa'), 'saved_at' => CarbonImmutable::now(), ...$with,
    ]);
}

it('takes one item per product and store', function () {
    ['product' => $product] = Fb::sold();
    $customer = Fx::customer();
    wishlistTableRow($product, $customer);
    wishlistTableRow($product, $customer, ['store_id' => Fx::storeId('ae')]);
    wishlistTableRow($product, Fx::customer('layla@example.test'));

    expect(DB::table('feedback.wishlist_items')->count())->toBe(3)
        ->and(fn () => DB::transaction(fn () => wishlistTableRow($product, $customer)))->toThrow(QueryException::class, '"wishlist_items_pkey"');
});

it('refuses what is nothing', function (Closure $with, string $constraint) {
    ['product' => $product] = Fb::sold();
    $customer = Fx::customer();

    expect(fn () => DB::transaction(fn () => wishlistTableRow($product, $customer, $with())))->toThrow(QueryException::class, "\"{$constraint}\"");
})->with([
    'a store' => [fn () => ['store_id' => strtolower((string) Str::ulid())], 'wishlist_items_store'],
    'a product' => [fn () => ['product_id' => strtolower((string) Str::ulid())], 'wishlist_items_product'],
    'a customer' => [fn () => ['customer_id' => strtolower((string) Str::ulid())], 'wishlist_items_customer'],
]);

it('lets a deleted product take its items with it, and keeps a customer and a store from going', function () {
    $rules = collect(DB::select(<<<'SQL'
        SELECT conname, confdeltype FROM pg_constraint
        WHERE conrelid = 'feedback.wishlist_items'::regclass AND contype = 'f'
        SQL))->mapWithKeys(fn (stdClass $row): array => [(string) $row->conname => (string) $row->confdeltype])->sortKeys()->all();

    expect($rules)->toBe(['wishlist_items_customer' => 'r', 'wishlist_items_product' => 'c', 'wishlist_items_store' => 'r']);
});
