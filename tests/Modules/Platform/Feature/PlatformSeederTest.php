<?php

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\StoreDto;

use function Pest\Laravel\seed;

uses(RefreshDatabase::class);

it('seeds the three launch stores from the spec', function () {
    seed(PlatformSeeder::class);

    $stores = array_map(fn (StoreDto $store): array => [
        $store->code,
        $store->countryCode,
        $store->currencyCode,
        $store->taxRateBasisPoints,
        $store->timezone,
        $store->position,
    ], app(PlatformApi::class)->stores());

    expect($stores)->toBe([
        ['sa', 'SA', 'SAR', 1500, 'Asia/Riyadh', 1],
        ['eg', 'EG', 'EGP', 1400, 'Africa/Cairo', 2],
        ['ae', 'AE', 'AED', 500, 'Asia/Dubai', 3],
    ]);
});

it('seeds each currency with its sign, or letters where there is none', function () {
    seed(PlatformSeeder::class);
    $platform = app(PlatformApi::class);

    expect($platform->currency('SAR')?->sign)->toBe("\u{20C1}")
        ->and($platform->currency('AED')?->sign)->toBe("\u{20C3}")
        ->and($platform->currency('EGP')?->sign)->toBeNull()
        ->and($platform->currency('EGP')?->displaySymbol('ar'))->toBe('ج.م')
        ->and($platform->currency('SAR')?->abbreviation->ar)->toBe('ر.س')
        ->and($platform->currency('AED')?->abbreviation->ar)->toBe('د.إ');
});

it('can run again without duplicating anything', function () {
    seed(PlatformSeeder::class);
    seed(PlatformSeeder::class);

    expect(DB::table('platform.stores')->count())->toBe(3)
        ->and(DB::table('platform.currencies')->count())->toBe(3);
});
