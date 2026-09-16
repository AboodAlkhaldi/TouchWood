<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\Platform\Application\Command\UpdateStore\UpdateStore;
use Modules\Platform\Application\Command\UpdateStore\UpdateStoreHandler;
use Modules\Platform\Infrastructure\Eloquent\VersionedCache;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Events\StoreUpdated;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

it('lets a listener of an after-commit event read the new data', function () {
    seed(PlatformSeeder::class);
    app(PlatformApi::class)->stores(); // warm the cache
    $seenByListener = null;

    Event::listen(StoreUpdated::class, function () use (&$seenByListener): void {
        $seenByListener = app(PlatformApi::class)->storeByCode('sa')?->taxRateBasisPoints;
    });

    app(UpdateStoreHandler::class)->handle(new UpdateStore('sa', taxRateBasisPoints: 1600));

    expect($seenByListener)->toBe(1600);
});

it('never serves an old snapshot a slow reader wrote after the data changed', function () {
    $cache = new VersionedCache(app(Cache::class), 'testing:race');
    $readerLoaded = $cache->remember(fn (): string => 'old rows');

    // A reader missed the cache under the current version and is still building its snapshot...
    $staleVersion = app(Cache::class)->get('testing:race:version');

    // ...while a write commits and invalidates...
    $cache->invalidate();

    // ...and only then does the slow reader store what it loaded.
    app(Cache::class)->put("testing:race:{$staleVersion}", 'old rows', 60);

    expect($readerLoaded)->toBe('old rows')
        ->and($cache->remember(fn (): string => 'new rows'))->toBe('new rows');
});

it('invalidates only once the transaction commits', function () {
    $cache = new VersionedCache(app(Cache::class), 'testing:commit');
    $cache->remember(fn (): string => 'before');

    DB::transaction(function () use ($cache) {
        $cache->invalidate();

        // Not committed yet: other readers must still get the committed snapshot.
        expect($cache->remember(fn (): string => 'uncommitted'))->toBe('before');
    });

    expect($cache->remember(fn (): string => 'after'))->toBe('after');
});

it('drops cached stores when migrations run, so a rebuilt schema is not served from cache', function () {
    seed(PlatformSeeder::class);
    expect(app(PlatformApi::class)->storeByCode('xb'))->toBeNull();

    DB::table('platform.stores')->insert([
        'id' => strtolower((string) Str::ulid()),
        'code' => 'xb',
        'name' => json_encode(['ar' => 'متجر', 'en' => 'Store']),
        'country_code' => 'XB',
        'currency_code' => 'SAR',
        'tax_rate_basis_points' => 1500,
        'timezone' => 'UTC',
    ]);
    event(new MigrationsEnded('up'));

    expect(app(PlatformApi::class)->storeByCode('xb')?->code)->toBe('xb');
});
