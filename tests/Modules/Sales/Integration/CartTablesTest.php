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
use Tests\Modules\Catalog\Support\CatalogProducts as Px;

use function Pest\Laravel\seed;

/*
| The database's own refusals behind the cart's code (sales.md §5; handoff §5.3): every named CHECK and
| key of the carts and their lines, each row breaking that one rule only (lesson 112).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

/**
 * @param  array<string, mixed>  $with
 */
function cartTableCart(array $with = []): string
{
    $id = strtolower((string) Str::ulid());
    DB::table('sales.carts')->insert([
        'id' => $id, 'store_id' => Fx::storeId('sa'), 'customer_id' => null, 'guest_id' => strtolower((string) Str::ulid()),
        'created_at' => CarbonImmutable::now(), 'updated_at' => CarbonImmutable::now(), ...$with,
    ]);

    return $id;
}

/**
 * @param  array<string, mixed>  $with
 */
function cartTableLine(string $cartId, string $variantId, array $with = []): void
{
    DB::table('sales.cart_lines')->insert(['cart_id' => $cartId, 'variant_id' => $variantId, 'sale_mode' => 'RETAIL', 'quantity' => 1, 'added_at' => CarbonImmutable::now(), ...$with]);
}

it('takes a guest\'s cart and a customer\'s with a coupon, their lines in both modes', function () {
    ['variants' => [$sixty]] = Px::ready();
    $guest = cartTableCart();
    $customer = cartTableCart(['customer_id' => Fx::customer(), 'guest_id' => null, 'coupon_code' => 'SUMMER-15']);
    cartTableLine($guest, $sixty);
    cartTableLine($guest, $sixty, ['sale_mode' => 'WHOLESALE', 'quantity' => 1_000_000]);
    cartTableLine($customer, $sixty);

    expect([DB::table('sales.carts')->count(), DB::table('sales.cart_lines')->count()])->toBe([2, 3]);
});

it('refuses a cart the code would never write', function (Closure $with, string $constraint) {
    expect(fn () => DB::transaction(fn () => cartTableCart($with())))->toThrow(QueryException::class, "\"{$constraint}\"");
})->with([
    'nobody\'s' => [fn () => ['guest_id' => null], 'carts_buyer'],
    'both' => [fn () => ['customer_id' => Fx::customer()], 'carts_buyer'],
    'a guest with a coupon' => [fn () => ['coupon_code' => 'SUMMER-15'], 'carts_coupon_customer'],
    'an empty code' => [fn () => ['customer_id' => Fx::customer(), 'guest_id' => null, 'coupon_code' => ''], 'carts_coupon_code'],
    'a store that is nothing' => [fn () => ['store_id' => strtolower((string) Str::ulid())], 'carts_store'],
    'a customer who is nobody' => [fn () => ['customer_id' => strtolower((string) Str::ulid()), 'guest_id' => null], 'carts_customer'],
]);

it('keeps one cart a buyer a store', function () {
    $sara = Fx::customer();
    $guest = strtolower((string) Str::ulid());
    cartTableCart(['customer_id' => $sara, 'guest_id' => null]);
    cartTableCart(['guest_id' => $guest]);

    expect(fn () => DB::transaction(fn () => cartTableCart(['customer_id' => $sara, 'guest_id' => null])))->toThrow(QueryException::class, '"carts_one_per_customer"')
        ->and(fn () => DB::transaction(fn () => cartTableCart(['guest_id' => $guest])))->toThrow(QueryException::class, '"carts_one_per_guest"');
});

it('refuses a line the code would never write', function (Closure $with, string $constraint) {
    ['variants' => [$sixty]] = Px::ready();
    $cart = cartTableCart();

    expect(fn () => DB::transaction(fn () => cartTableLine($cart, $sixty, $with())))->toThrow(QueryException::class, "\"{$constraint}\"");
})->with([
    'another mode' => [fn () => ['sale_mode' => 'BULK'], 'cart_lines_sale_mode'],
    'none' => [fn () => ['quantity' => 0], 'cart_lines_quantity'],
    'too many' => [fn () => ['quantity' => 1_000_001], 'cart_lines_quantity'],
    'a size that is nothing' => [fn () => ['variant_id' => strtolower((string) Str::ulid())], 'cart_lines_variant'],
    'a cart that is nothing' => [fn () => ['cart_id' => strtolower((string) Str::ulid())], 'cart_lines_cart'],
]);

it('keeps the same size and mode once a cart, and lets a deleted size or account take its lines and carts', function () {
    ['variants' => [$sixty]] = Px::ready();
    $cart = cartTableCart();
    cartTableLine($cart, $sixty);

    expect(fn () => DB::transaction(fn () => cartTableLine($cart, $sixty)))->toThrow(QueryException::class, '"cart_lines_pkey"');

    $rules = collect(DB::select("SELECT conname, confdeltype FROM pg_constraint WHERE conrelid IN ('sales.carts'::regclass, 'sales.cart_lines'::regclass) AND contype = 'f'"))
        ->mapWithKeys(fn (stdClass $row): array => [(string) $row->conname => (string) $row->confdeltype])->sortKeys()->all();

    expect($rules)->toBe(['cart_lines_cart' => 'c', 'cart_lines_variant' => 'c', 'carts_customer' => 'c', 'carts_store' => 'r']);
});
