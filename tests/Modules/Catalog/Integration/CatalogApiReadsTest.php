<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Catalog\Application\Command\ArchiveVariant\ArchiveVariant;
use Modules\Catalog\Application\Command\ArchiveVariant\ArchiveVariantHandler;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Catalog\Application\Command\MoveCategory\MoveCategory;
use Modules\Catalog\Application\Command\MoveCategory\MoveCategoryHandler;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Dto\ProductDto;
use Modules\Catalog\Public\Dto\VariantDto;
use Modules\Catalog\Public\Enums\ProductStage;
use Modules\Catalog\Public\Events\CategoryMoved;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogFixtures as Cx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;

use function Pest\Laravel\seed;

/*
| What Pricing and Inventory ask Catalog (catalog.md §2.1, amendment 16(e), (i)): a product's variants
| in its order, the products in a category and below it, the variants switched on in a store, many
| variants and many products in one read — a fixed number of queries however many — and
| `CategoryMoved` when a category moves.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

function catalogReads(): CatalogApi
{
    return app(CatalogApi::class);
}

describe('a product\'s variants and a category\'s products', function () {
    it('answers a product\'s variants in its own order, archived ones only when asked', function () {
        $ready = Px::ready(['60 cm', '80 cm', '90 cm']);
        [$sixty, $eighty, $ninety] = $ready['variants'];
        // Added in order, 1, 2, 3 (amendment 16(d)); 60 cm moved past the others.
        DB::table('catalog.variants')->where('id', $sixty)->update(['position' => 4]);
        Fx::asSystem(fn () => app(ArchiveVariantHandler::class)->handle(new ArchiveVariant($eighty)));

        expect(catalogReads()->variantIdsOf(strtoupper($ready['product'])))->toBe([$ninety, $sixty])
            ->and(catalogReads()->variantIdsOf($ready['product'], includeArchived: true))->toBe([$eighty, $ninety, $sixty])
            ->and(catalogReads()->variantIdsOf('01k6abcdefghjkmnpqrstvwxyz'))->toBe([])
            ->and(catalogReads()->variantIdsOf('not-an-id'))->toBe([]);
    });

    it('answers the products in a category and in every category below it, any stage', function () {
        $kitchens = Px::category('Kitchens');
        $drawers = Px::category('Drawers', $kitchens);
        $runners = Px::category('Runners', $drawers);
        $ready = Px::ready(['60 cm'], $runners);
        $draft = Px::product('Hinge');
        DB::table('catalog.products')->where('id', $draft)->update(['category_id' => $drawers]);
        Px::ready(['60 cm'], Px::category('Wardrobes'));
        $expected = [$ready['product'], $draft];
        sort($expected);

        $found = catalogReads()->productIdsInCategory($kitchens);
        sort($found);

        expect($found)->toBe($expected)
            ->and(catalogReads()->productIdsInCategory($runners))->toBe([$ready['product']])
            ->and(catalogReads()->productIdsInCategory('01k6abcdefghjkmnpqrstvwxyz'))->toBe([]);
    });

    it('tells the modules above when a category moves, and not when it stays', function () {
        Event::fake([CategoryMoved::class]);
        $kitchens = Px::category('Kitchens');
        $baths = Px::category('Baths');
        $drawers = Px::category('Drawers', $kitchens);

        Fx::asSystem(fn () => app(MoveCategoryHandler::class)->handle(new MoveCategory($drawers, $kitchens)));
        Event::assertNotDispatched(CategoryMoved::class);

        Fx::asSystem(fn () => app(MoveCategoryHandler::class)->handle(new MoveCategory($drawers, $baths)));
        Event::assertDispatchedTimes(CategoryMoved::class, 1);
        Event::assertDispatched(CategoryMoved::class, fn (CategoryMoved $event): bool => $event->categoryId === $drawers);
    });
});

describe('a store\'s variants', function () {
    it('answers the variants switched on in a store, and only there', function () {
        $ready = Px::ready(['60 cm', '80 cm']);
        [$sixty, $eighty] = $ready['variants'];
        Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId('sa'), $ready['product'], true, [$sixty])));

        expect(catalogReads()->switchedOnVariantIds(StoreId::fromString(Fx::storeId('sa'))))->toBe([$sixty])
            ->and(catalogReads()->switchedOnVariantIds(StoreId::fromString(Fx::storeId('eg'))))->toBe([]);

        Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId('sa'), $ready['product'], false, [$sixty])));

        expect(catalogReads()->switchedOnVariantIds(StoreId::fromString(Fx::storeId('sa'))))->toBe([])
            ->and($eighty)->toBeString();
    });
});

describe('many at once', function () {
    it('answers many variants keyed by id, as one would be answered, leaving out an unknown id', function () {
        $ready = Px::ready(['60 cm', '80 cm']);
        [$sixty, $eighty] = $ready['variants'];
        DB::table('catalog.variants')->where('id', $eighty)->update(['weight_grams' => 900]);

        $found = catalogReads()->variants([strtoupper($sixty), $eighty, '01k6abcdefghjkmnpqrstvwxyz', 'not-an-id', $sixty]);

        expect(array_keys($found))->toEqualCanonicalizing([$sixty, $eighty])
            ->and($found[$eighty])->toBeInstanceOf(VariantDto::class)
            ->and($found[$eighty])->toEqual(catalogReads()->variant($eighty))
            ->and($found[$sixty])->toEqual(catalogReads()->variant($sixty))
            ->and($found[$eighty]->weightGrams)->toBe(900)
            ->and($found[$eighty]->values[0]->value->en)->toBe('80 cm')
            ->and(catalogReads()->variants([]))->toBe([]);
    });

    it('answers many products keyed by id, a draft with its Arabic name only included', function () {
        $ready = Px::ready();
        $draft = Px::product(null);

        $found = catalogReads()->products([$ready['product'], strtoupper($draft), '01k6abcdefghjkmnpqrstvwxyz']);

        expect(array_keys($found))->toEqualCanonicalizing([$ready['product'], $draft])
            ->and($found[$draft])->toBeInstanceOf(ProductDto::class)
            ->and($found[$draft])->toEqual(catalogReads()->product($draft))
            ->and($found[$draft]->stage)->toBe(ProductStage::Draft)
            ->and($found[$ready['product']])->toEqual(catalogReads()->product($ready['product']))
            ->and(catalogReads()->products(['nothing']))->toBe([]);
    });

    it('reads many variants and many products in the same number of queries however many there are', function () {
        $few = Px::ready(['60 cm']);
        $many = Px::ready(['60 cm', '70 cm', '80 cm', '90 cm', '100 cm']);
        $count = function (callable $read): int {
            $queries = Cx::recordQueries();
            $read();

            return count($queries);
        };

        expect($count(fn () => catalogReads()->variants($few['variants'])))->toBe($count(fn () => catalogReads()->variants($many['variants'])))
            ->and($count(fn () => catalogReads()->products([$few['product']])))->toBe($count(fn () => catalogReads()->products([$few['product'], $many['product']])));
    });
});
