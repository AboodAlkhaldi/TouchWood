<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;

use function Pest\Laravel\seed;

/*
| Many variants by code in one read (catalog.md amendment 21), for Sync: keyed by each code as given and
| read as `variantByCode` reads one; archived ones too; a code that is not one, or one no variant
| carries, left out; a few queries however many codes.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

it('finds each variant by its code as given - spaces around, Arabic-Indic digits - archived ones too, and leaves out what no variant carries', function () {
    [$plain, $archived] = Px::ready(['60 cm', '80 cm'])['variants'];
    DB::table('catalog.variants')->where('id', $archived)->update(['is_archived' => true]);
    $code = static fn (string $variantId): string => (string) DB::table('catalog.variants')->where('id', $variantId)->value('code');
    $arabic = strtr($code($plain), ['0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤', '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩']);

    $found = app(CatalogApi::class)->variantsByCodes([$code($plain), ' '.$code($archived).' ', $arabic, '7199999', 'not a code']);

    expect(array_map(static fn ($variant): string => $variant->id, $found))->toBe([
        $code($plain) => $plain,
        ' '.$code($archived).' ' => $archived,
        $arabic => $plain,
    ]);
});

it('reads many codes in a few queries, not one each', function () {
    $sizes = Px::ready(array_map(static fn (int $n): string => "{$n} cm", range(1, 30)))['variants'];
    $codes = array_values(array_map('strval', DB::table('catalog.variants')->whereIn('id', $sizes)->pluck('code')->all()));

    DB::enableQueryLog();
    DB::flushQueryLog();
    $found = app(CatalogApi::class)->variantsByCodes($codes);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($found)->toHaveCount(30)
        ->and($queries)->toBeLessThanOrEqual(4);
});

it('finds nothing for no codes', function () {
    expect(app(CatalogApi::class)->variantsByCodes([]))->toBe([]);
});
