<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\ParallelTesting;
use Tests\Modules\Sales\Support\CheckoutFixtures as Cx;

use function Pest\Laravel\seed;

/*
| Placing one quote twice at once (sales.md §1.3 step 1; b2b.md §9 #7): the second waits for the first's
| lock on the quote, then finds its order — one order, however often the button is pressed.
|
| Outside RefreshDatabase's transaction (lesson 87): it commits its own rows and empties the database
| before and after itself; skipped under --parallel.
*/

function placeRaceInParallel(): bool
{
    return ParallelTesting::token() !== false;
}

beforeEach(function () {
    if (placeRaceInParallel()) {
        return;
    }

    Artisan::call('migrate:fresh');
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    config(['database.connections.place_race' => config('database.connections.'.config('database.default'))]);
});

afterEach(function () {
    if (placeRaceInParallel()) {
        return;
    }

    DB::connection('place_race')->disconnect();
    DB::statement('SET lock_timeout = 0');
    Cache::flush();
    app()->forgetScopedInstances();
    Artisan::call('migrate:fresh');
});

it('lets a second placement of a quote wait on the first, then answers the first order', function () {
    ['address' => $address] = Cx::ready();
    $quote = Cx::start($address);
    $other = DB::connection('place_race');

    // The first placement, elsewhere, holds the quote's row.
    $other->beginTransaction();
    $other->table('sales.quotes')->where('id', $quote->id)->lockForUpdate()->first();
    DB::statement("SET lock_timeout = '500ms'");

    $waited = null;

    try {
        Cx::place($quote->id);
    } catch (QueryException $timedOut) {
        $waited = $timedOut;
    } finally {
        $other->rollBack();
    }

    expect($waited?->getCode())->toBe('55P03')
        ->and(strtolower((string) $waited?->getSql()))->toContain('"sales"."quotes"')
        ->and(strtolower((string) $waited?->getSql()))->toContain('for update')
        ->and(DB::table('sales.orders')->count())->toBe(0);

    $placed = Cx::place($quote->id);

    expect(Cx::place($quote->id))->toEqual($placed)
        ->and(DB::table('sales.orders')->count())->toBe(1);
})->skip(fn (): bool => placeRaceInParallel(), 'Empties its database: run serially.');
