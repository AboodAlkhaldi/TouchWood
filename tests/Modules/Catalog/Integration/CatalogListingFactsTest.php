<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Public\Contracts\ListingFacts;
use Modules\Catalog\Public\Dto\ListingPrice;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;

use function Pest\Laravel\seed;

/*
| What the modules above push into Catalog (catalog.md §2.2; amendments 15, 16(h), 16(i)): `ListingFacts`
| keeps each fact in Catalog's own table, only the facts given. (The import's sections, which carried a
| store file's prices and stock, went with them - amendment 42.)
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

/** @return array<string, mixed>|null */
function catalogFacts(string $store, string $variantId): ?array
{
    $row = DB::table('catalog.store_variant_facts')->where('store_id', Fx::storeId($store))->where('variant_id', $variantId)->first(['orderable', 'ending_soon', 'price_minor', 'price_before_minor', 'currency']);

    return $row === null ? null : (array) $row;
}

describe('the listing\'s facts', function () {
    it('keeps whether a variant can be ordered and whether it is ending soon, each fact apart, in that store only', function () {
        [$sixty, $eighty] = Px::ready(['60 cm', '80 cm'])['variants'];
        $facts = app(ListingFacts::class);
        $sa = StoreId::fromString(Fx::storeId('sa'));

        $facts->orderable($sa, [$sixty, strtoupper($eighty), '01k6abcdefghjkmnpqrstvwxyz', 'not-an-id'], false);
        $facts->endingSoon($sa, [$sixty], true);

        expect(catalogFacts('sa', $sixty))->toBe(['orderable' => false, 'ending_soon' => true, 'price_minor' => null, 'price_before_minor' => null, 'currency' => null])
            ->and(catalogFacts('sa', $eighty))->toMatchArray(['orderable' => false, 'ending_soon' => false])
            ->and(catalogFacts('eg', $sixty))->toBeNull()
            ->and(DB::table('catalog.store_variant_facts')->count())->toBe(2);

        $facts->orderable($sa, [$sixty], true);

        expect(catalogFacts('sa', $sixty))->toMatchArray(['orderable' => true, 'ending_soon' => true]);
    });

    it('keeps a variant\'s price now and before, and clears it when none is given, leaving the other facts', function () {
        [$sixty, $eighty] = Px::ready(['60 cm', '80 cm'])['variants'];
        $facts = app(ListingFacts::class);
        $sa = StoreId::fromString(Fx::storeId('sa'));
        $facts->orderable($sa, [$sixty], false);

        $facts->prices($sa, [$sixty => new ListingPrice(Money::of(9_000, 'SAR'), Money::of(12_000, 'SAR')), $eighty => new ListingPrice(Money::of(15_000, 'SAR'))]);

        expect(catalogFacts('sa', $sixty))->toBe(['orderable' => false, 'ending_soon' => false, 'price_minor' => 9000, 'price_before_minor' => 12000, 'currency' => 'SAR'])
            ->and(catalogFacts('sa', $eighty))->toMatchArray(['price_minor' => 15000, 'price_before_minor' => null, 'currency' => 'SAR']);

        $facts->prices($sa, [$sixty => null]);

        expect(catalogFacts('sa', $sixty))->toBe(['orderable' => false, 'ending_soon' => false, 'price_minor' => null, 'price_before_minor' => null, 'currency' => null]);
    });

    it('takes a price before only above the price now, in its currency (sales ranks: CatalogSalesRanksTest)', function () {
        expect(fn () => new ListingPrice(Money::of(100, 'SAR'), Money::of(100, 'SAR')))->toThrow(InvalidArgumentException::class)
            ->and(fn () => new ListingPrice(Money::of(100, 'SAR'), Money::of(200, 'EGP')))->toThrow(InvalidArgumentException::class);
    });
});
