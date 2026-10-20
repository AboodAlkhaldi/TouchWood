<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\ParallelTesting;
use Modules\Catalog\Public\Enums\SaleMode;
use Modules\Sales\Infrastructure\Eloquent\DatabaseCarts;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;

use function Pest\Laravel\seed;

/*
| Two first lines at once for the same buyer (sales.md §1.1): one cart, never two and never a refused
| add — the second waits for the first's lock on the cart, then adds to it.
|
| Outside RefreshDatabase's transaction (lesson 87): it commits its own rows and empties the database
| before and after itself; skipped under --parallel.
*/

function cartRaceInParallel(): bool
{
    return ParallelTesting::token() !== false;
}

beforeEach(function () {
    if (cartRaceInParallel()) {
        return;
    }

    Artisan::call('migrate:fresh');
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    config(['database.connections.cart_race' => config('database.connections.'.config('database.default'))]);
});

afterEach(function () {
    if (cartRaceInParallel()) {
        return;
    }

    DB::connection('cart_race')->disconnect();
    // The store directory read during the test is cached: the wipe must not meet the stores it removes.
    Cache::flush();
    app()->forgetScopedInstances();
    Artisan::call('migrate:fresh');
});

it('makes one cart of two first lines at once, the second waiting for the first', function () {
    ['variants' => [$sixty, $eighty]] = Px::ready(['60 cm', '80 cm']);
    $sara = Fx::customer();
    $store = StoreId::fromString(Fx::storeId('sa'));
    $first = DB::connection();
    $second = DB::connection('cart_race');
    $mine = new DatabaseCarts($first);
    $theirs = new DatabaseCarts($second);
    $second->statement("SET lock_timeout = '500ms'");

    $first->beginTransaction();

    try {
        $cart = $mine->lockOrCreate($store, $sara, null, CarbonImmutable::now());
        $mine->add($cart->id, $sixty, SaleMode::Retail, 1, CarbonImmutable::now());

        // The second, meanwhile, stops on the first's cart: its row is the first's until it commits.
        $waited = null;

        try {
            $second->transaction(fn () => $theirs->lockOrCreate($store, $sara, null, CarbonImmutable::now()));
        } catch (QueryException $timedOut) {
            $waited = $timedOut;
        }

        expect($waited?->getCode())->toBe('55P03');

        $first->commit();
    } catch (Throwable $failed) {
        $first->rollBack();

        throw $failed;
    }

    $second->transaction(function () use ($theirs, $store, $sara, $eighty): void {
        $cart = $theirs->lockOrCreate($store, $sara, null, CarbonImmutable::now());
        $theirs->add($cart->id, $eighty, SaleMode::Retail, 1, CarbonImmutable::now());
    });

    expect(DB::table('sales.carts')->count())->toBe(1)
        ->and(DB::table('sales.cart_lines')->count())->toBe(2);
})->skip(fn (): bool => cartRaceInParallel(), 'Empties its database: run serially.');

it('lets a change to a cart that exists wait for the one before it, and lose nothing', function () {
    ['variants' => [$sixty, $eighty]] = Px::ready(['60 cm', '80 cm']);
    $sara = Fx::customer();
    $store = StoreId::fromString(Fx::storeId('sa'));
    $first = DB::connection();
    $second = DB::connection('cart_race');
    $mine = new DatabaseCarts($first);
    $theirs = new DatabaseCarts($second);
    $first->transaction(fn () => $mine->lockOrCreate($store, $sara, null, CarbonImmutable::now()));
    $second->statement("SET lock_timeout = '500ms'");

    $first->beginTransaction();

    try {
        $cart = $mine->lockOrCreate($store, $sara, null, CarbonImmutable::now());
        $mine->add($cart->id, $sixty, SaleMode::Retail, 1, CarbonImmutable::now());

        // The cart is there: the second gets past its insert at once and waits on the row's lock.
        $waited = null;

        try {
            $second->transaction(fn () => $theirs->lockOrCreate($store, $sara, null, CarbonImmutable::now()));
        } catch (QueryException $timedOut) {
            $waited = $timedOut;
        }

        expect($waited?->getCode())->toBe('55P03')
            ->and(strtolower((string) $waited?->getSql()))->toContain('for update');

        $first->commit();
    } catch (Throwable $failed) {
        $first->rollBack();

        throw $failed;
    }

    $second->transaction(function () use ($theirs, $store, $sara, $eighty): void {
        $cart = $theirs->lockOrCreate($store, $sara, null, CarbonImmutable::now());
        $theirs->add($cart->id, $eighty, SaleMode::Retail, 1, CarbonImmutable::now());
    });

    expect(DB::table('sales.carts')->count())->toBe(1)
        ->and(DB::table('sales.cart_lines')->count())->toBe(2);
})->skip(fn (): bool => cartRaceInParallel(), 'Empties its database: run serially.');
