<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Application\Command\ArchiveProduct\ArchiveProduct;
use Modules\Catalog\Application\Command\ArchiveProduct\ArchiveProductHandler;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;

use function Pest\Laravel\seed;

/*
| Every size's code, for Sync's whole reads (catalog.md amendment 44; sync.md §2.4): each variant not
| archived, of a product not archived - drafts and sizes switched on nowhere included - keyed by id; one
| query however many.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

function catalogVariantCodesOf(string $variantId): string
{
    return (string) DB::table('catalog.variants')->where('id', $variantId)->value('code');
}

it('keys each code by its variant, leaving out an archived variant and every variant of an archived product', function () {
    [$ready, $archived] = Px::ready(['60 cm', '80 cm'])['variants'];
    DB::table('catalog.variants')->where('id', $archived)->update(['is_archived' => true]);
    $draft = Px::variant(Px::product(), '7100001');
    $gone = Px::ready(['90 cm']);
    Fx::asSystem(fn () => app(ArchiveProductHandler::class)->handle(new ArchiveProduct($gone['product'])));

    $codes = app(CatalogApi::class)->variantCodes();

    expect($codes)->toMatchArray([$ready => catalogVariantCodesOf($ready), $draft => '7100001'])
        ->and($codes)->not->toHaveKey($archived)
        ->and($codes)->not->toHaveKey($gone['variants'][0])
        ->and(array_keys($codes))->toBe(array_values(array_map('strval', DB::table('catalog.variants as v')->join('catalog.products as p', 'p.id', '=', 'v.product_id')
        ->where('v.is_archived', false)->where('p.stage', '<>', 'ARCHIVED')->orderBy('v.id')->pluck('v.id')->all())));
});

it('reads every code in one query, however many', function () {
    Px::ready(array_map(static fn (int $n): string => "{$n} cm", range(1, 30)));

    DB::enableQueryLog();
    DB::flushQueryLog();
    $codes = app(CatalogApi::class)->variantCodes();
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect(count($codes))->toBeGreaterThanOrEqual(30)
        ->and($queries)->toBe(1);
});
