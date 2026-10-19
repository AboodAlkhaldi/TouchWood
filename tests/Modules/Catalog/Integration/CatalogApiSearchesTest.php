<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Catalog\Application\Command\ArchiveVariant\ArchiveVariant;
use Modules\Catalog\Application\Command\ArchiveVariant\ArchiveVariantHandler;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Dto\FoundProductDto;
use Modules\Catalog\Public\Dto\FoundVariantDto;
use Modules\Catalog\Public\Enums\ProductStage;
use Modules\Platform\Public\Dto\TranslatedTextDto;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogFixtures as Cx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;

use function Pest\Laravel\seed;

/*
| What stage 5's store-wide screens ask Catalog (catalog.md amendment 23; pricing.md §4.3.3): a store's
| sizes and products in Catalog's order - product name in the page's language, then the size's place -
| keyset-paged with their totals, narrowed by the asking module's own sizes (`only`, `except`); a
| category's path and its products; each size's wholesale terms. Each a fixed few queries.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    app()->setLocale('en');
});

function catalogSearches(): CatalogApi
{
    return app(CatalogApi::class);
}

function catalogSearchesStore(string $code = 'sa'): StoreId
{
    return StoreId::fromString(Fx::storeId($code));
}

/**
 * A ready product with these sizes, named for the order, switched on in the store unless told not.
 *
 * @param  list<string>  $sizes
 * @return array{product: string, variants: list<string>, width: string}
 */
function catalogSearchesProduct(string $nameEn, string $nameAr, array $sizes = ['60 cm'], ?string $store = 'sa'): array
{
    $ready = Px::ready($sizes);
    DB::table('catalog.products')->where('id', $ready['product'])->update(['name_en' => $nameEn, 'name_ar' => $nameAr]);

    if ($store !== null) {
        Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId($store), $ready['product'], true)));
    }

    return $ready;
}

/**
 * @param  list<FoundVariantDto>  $rows
 * @return list<string>
 */
function catalogSearchesIds(array $rows): array
{
    return array_map(static fn (FoundVariantDto $row): string => $row->variantId, $rows);
}

/**
 * How many queries a search of these sizes asks, the first of them left out.
 *
 * @param  list<string>  $only
 */
function catalogSearchesQueries(array $only): int
{
    $queries = Cx::recordQueries();
    catalogSearches()->searchVariants(catalogSearchesStore(), 'a', only: $only, except: $only === [] ? null : [$only[0]]);

    return count($queries);
}

describe('searchVariants', function () {
    it('leaves out an archived size, and a size of an archived product, when asked (Pricing\'s Needs a Price)', function () {
        $drawer = catalogSearchesProduct('Drawer', 'درج', ['60 cm', '80 cm']);
        Fx::asSystem(fn () => app(ArchiveVariantHandler::class)->handle(new ArchiveVariant($drawer['variants'][1])));
        $queries = Cx::recordQueries();

        // Archiving switches a size off, so it matters for the sizes named: `only` answers them whatever their state.
        $kept = catalogSearches()->searchVariants(catalogSearchesStore(), null, only: $drawer['variants'], notArchived: true);

        expect(catalogSearchesIds($kept->rows))->toBe([$drawer['variants'][0]])
            ->and($kept->total)->toBe(1)
            ->and(count($queries))->toBe(2)
            ->and(catalogSearches()->searchVariants(catalogSearchesStore(), null, only: $drawer['variants'])->total)->toBe(2);
    });

    it('pages the store\'s switched-on sizes by product name in the page\'s language, then the size\'s place, with the total', function () {
        $beta = catalogSearchesProduct('Beta Drawer', 'أدراج', ['60 cm', '80 cm']);
        $alpha = catalogSearchesProduct('Alpha Hinge', 'مفصلة');
        $gamma = catalogSearchesProduct('Gamma Rail', 'بكرة');
        catalogSearchesProduct('Not Here', 'ليس هنا', store: 'eg');
        // The size's place, not its age: 80 cm moved before 60 cm.
        DB::table('catalog.variants')->where('id', $beta['variants'][1])->update(['position' => 0]);

        $first = catalogSearches()->searchVariants(catalogSearchesStore(), null, limit: 2);
        $second = catalogSearches()->searchVariants(catalogSearchesStore(), null, after: $first->next, limit: 2);

        expect(catalogSearchesIds($first->rows))->toBe([$alpha['variants'][0], $beta['variants'][1]])
            ->and($first->total)->toBe(4)
            ->and($first->next)->not->toBeNull()
            ->and(catalogSearchesIds($second->rows))->toBe([$beta['variants'][0], $gamma['variants'][0]])
            ->and($second->next)->toBeNull()
            ->and($second->total)->toBe(4);

        // In Arabic, by the Arabic names: أدراج, بكرة, مفصلة.
        app()->setLocale('ar');
        expect(catalogSearchesIds(catalogSearches()->searchVariants(catalogSearchesStore(), null)->rows))
            ->toBe([$beta['variants'][1], $beta['variants'][0], $gamma['variants'][0], $alpha['variants'][0]]);
    });

    it('pages on without a gap or a repeat when two products share a name and a page ends inside a product\'s sizes', function () {
        $first = catalogSearchesProduct('Drawer', 'درج', ['60 cm', '80 cm', '90 cm']);
        $second = catalogSearchesProduct('Drawer', 'درج', ['60 cm', '80 cm']);
        catalogSearchesProduct('Alpha', 'أ');
        // The products' own order, after the name: by id, byte by byte.
        $byId = [$first, $second];
        usort($byId, static fn (array $a, array $b): int => strcmp($a['product'], $b['product']));
        $expected = [...$byId[0]['variants'], ...$byId[1]['variants']];

        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);
            $seen = [];
            $after = null;
            $pages = 0;

            // Two at a time: the first page ends inside the first Drawer, the second crosses into the next.
            do {
                $page = catalogSearches()->searchVariants(catalogSearchesStore(), 'drawer', after: $after, limit: 2);
                $seen = [...$seen, ...catalogSearchesIds($page->rows)];
                $after = $page->next;
                $pages++;
                expect($page->total)->toBe(5);
            } while ($after !== null && $pages < 10);

            expect($seen)->toBe($expected)->and($pages)->toBe(3);

            // The products alike, one a page.
            $one = catalogSearches()->searchProducts(catalogSearchesStore(), 'drawer', true, limit: 1);
            $two = catalogSearches()->searchProducts(catalogSearchesStore(), 'drawer', true, after: $one->next, limit: 1);
            expect(array_map(static fn (FoundProductDto $row): string => $row->productId, [...$one->rows, ...$two->rows]))->toBe([$byId[0]['product'], $byId[1]['product']])
                ->and($two->next)->toBeNull();
        }
    });

    it('answers each size\'s product names, photo, values, code and marks', function () {
        $ready = catalogSearchesProduct('Drawer', 'درج', ['60 cm']);
        [$sixty] = $ready['variants'];

        $rows = catalogSearches()->searchVariants(catalogSearchesStore(), null)->rows;
        expect($rows)->toHaveCount(1);
        [$row] = $rows;

        expect($row->productId)->toBe($ready['product'])
            ->and($row->productNameEn)->toBe('Drawer')
            ->and($row->productNameAr)->toBe('درج')
            ->and($row->code)->toBe((string) DB::table('catalog.variants')->where('id', $sixty)->value('code'))
            ->and($row->photoMediaId)->toBe((string) DB::table('catalog.product_photos')->where('product_id', $ready['product'])->value('media_id'))
            ->and(array_map(static fn ($value): TranslatedTextDto => $value->value, $row->values))->toEqual([new TranslatedTextDto((string) DB::table('catalog.attribute_values')->where('name_en', '60 cm')->value('name_ar'), '60 cm')])
            ->and($row->switchedOn)->toBeTrue()
            ->and($row->archived)->toBeFalse();
    });

    it('takes exactly the sizes named in only, switched off and archived ones included, and leaves out those in except', function () {
        $on = catalogSearchesProduct('Alpha', 'أ', ['60 cm', '80 cm']);
        $off = catalogSearchesProduct('Beta', 'ب', ['60 cm'], store: null);
        Fx::asSystem(fn () => app(ArchiveVariantHandler::class)->handle(new ArchiveVariant($on['variants'][1])));
        $only = [$on['variants'][1], strtoupper($off['variants'][0]), 'not-an-id'];

        $found = catalogSearches()->searchVariants(catalogSearchesStore(), null, only: $only);

        expect(catalogSearchesIds($found->rows))->toBe([$on['variants'][1], $off['variants'][0]])
            ->and($found->total)->toBe(2)
            ->and($found->rows[0]->archived)->toBeTrue()
            ->and($found->rows[1]->switchedOn)->toBeFalse()
            ->and(catalogSearches()->searchVariants(catalogSearchesStore(), null, only: [])->total)->toBe(0)
            ->and(catalogSearchesIds(catalogSearches()->searchVariants(catalogSearchesStore(), null, except: [$on['variants'][0]])->rows))->toBe([])
            ->and(catalogSearchesIds(catalogSearches()->searchVariants(catalogSearchesStore(), null, only: $only, except: [$off['variants'][0]])->rows))->toBe([$on['variants'][1]]);
    });

    it('takes every ready product\'s sizes not archived with anyReady, and only those sold wholesale with soldWholesale', function () {
        $on = catalogSearchesProduct('Alpha', 'أ', ['60 cm', '80 cm']);
        $elsewhere = catalogSearchesProduct('Beta', 'ب', ['60 cm'], store: 'eg');
        Px::product('Gamma draft');
        Fx::asSystem(fn () => app(ArchiveVariantHandler::class)->handle(new ArchiveVariant($on['variants'][1])));
        DB::table('catalog.store_variants')->where('variant_id', $on['variants'][0])->update(['sells_wholesale' => true]);

        expect(catalogSearchesIds(catalogSearches()->searchVariants(catalogSearchesStore(), null, anyReady: true)->rows))->toBe([$on['variants'][0], $elsewhere['variants'][0]])
            ->and(catalogSearchesIds(catalogSearches()->searchVariants(catalogSearchesStore(), null, soldWholesale: true)->rows))->toBe([$on['variants'][0]]);

        DB::table('catalog.store_variants')->where('variant_id', $on['variants'][0])->update(['sells_wholesale' => false]);
        expect(catalogSearches()->searchVariants(catalogSearchesStore(), null, soldWholesale: true)->total)->toBe(0);
    });

    it('finds by a name in either language, or a size\'s code typed in either kind of digits', function () {
        $hinge = catalogSearchesProduct('Soft Hinge', 'مفصلة ناعمة');
        $drawer = catalogSearchesProduct('Drawer', 'درج', ['60 cm', '80 cm']);
        $code = (string) DB::table('catalog.variants')->where('id', $drawer['variants'][1])->value('code');
        $arabicDigits = strtr($code, ['0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤', '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩']);

        expect(catalogSearchesIds(catalogSearches()->searchVariants(catalogSearchesStore(), 'hinge')->rows))->toBe([$hinge['variants'][0]])
            ->and(catalogSearchesIds(catalogSearches()->searchVariants(catalogSearchesStore(), 'ناعمة')->rows))->toBe([$hinge['variants'][0]])
            ->and(catalogSearchesIds(catalogSearches()->searchVariants(catalogSearchesStore(), " {$code} ")->rows))->toBe([$drawer['variants'][1]])
            ->and(catalogSearchesIds(catalogSearches()->searchVariants(catalogSearchesStore(), $arabicDigits)->rows))->toBe([$drawer['variants'][1]])
            // A wildcard typed is a character, not a pattern.
            ->and(catalogSearches()->searchVariants(catalogSearchesStore(), '%')->total)->toBe(0)
            ->and(catalogSearches()->searchVariants(catalogSearchesStore(), '   ')->total)->toBe(3);
    });

    it('starts at the top for a cursor that is not one, and keeps the limit from 1 to 100', function () {
        $sizes = array_map(static fn (int $n): string => "{$n}0 cm", range(1, 3));
        catalogSearchesProduct('Drawer', 'درج', $sizes);

        $top = catalogSearchesIds(catalogSearches()->searchVariants(catalogSearchesStore(), null, limit: 1)->rows);

        foreach (['', 'not base64!', base64_encode('[1,2]'), rtrim(strtr(base64_encode('["a","x",1,"y"]'), '+/', '-_'), '=')] as $cursor) {
            expect(catalogSearchesIds(catalogSearches()->searchVariants(catalogSearchesStore(), null, after: $cursor, limit: 1)->rows))->toBe($top);
        }

        expect(catalogSearches()->searchVariants(catalogSearchesStore(), null, limit: 0)->rows)->toHaveCount(1)
            ->and(catalogSearches()->searchVariants(catalogSearchesStore(), null, limit: 500)->rows)->toHaveCount(3);
    });

    it('reads a page in two queries however many sizes it shows or ids it is given', function () {
        catalogSearchesProduct('Drawer', 'درج', ['60 cm', '80 cm']);
        $few = catalogSearchesQueries([]);
        $ids = array_values(array_map('strval', DB::table('catalog.variants')->pluck('id')->all()));
        $many = array_merge($ids, array_map(static fn (int $n): string => strtolower((string) Str::ulid()), range(1, 2000)));

        expect(catalogSearches()->searchVariants(catalogSearchesStore(), null)->total)->toBe(2);
        $queries = Cx::recordQueries();
        catalogSearches()->searchVariants(catalogSearchesStore(), null);
        expect(count($queries))->toBe(2)
            ->and(catalogSearchesQueries($many))->toBe(2)
            // Nothing asked of an empty `only`: nothing can be found.
            ->and($few)->toBe(0);
    });
});

describe('searchProducts and productsInCategory', function () {
    it('offers the store\'s ready, switched-on products for a picker, by name, paged with the total', function () {
        $beta = catalogSearchesProduct('Beta', 'ب');
        $alpha = catalogSearchesProduct('Alpha', 'أ');
        catalogSearchesProduct('Elsewhere', 'هناك', store: 'eg');
        $draft = Px::product('Draft');

        $first = catalogSearches()->searchProducts(catalogSearchesStore(), null, true, limit: 1);
        $second = catalogSearches()->searchProducts(catalogSearchesStore(), null, true, after: $first->next, limit: 1);

        expect(array_map(static fn (FoundProductDto $row): string => $row->productId, [...$first->rows, ...$second->rows]))->toBe([$alpha['product'], $beta['product']])
            ->and($first->total)->toBe(2)
            ->and($second->next)->toBeNull()
            ->and($first->rows[0]->stage)->toBe(ProductStage::Ready)
            ->and($first->rows[0]->codes)->toBe([(string) DB::table('catalog.variants')->where('product_id', $alpha['product'])->value('code')])
            ->and($first->rows[0]->photoMediaId)->not->toBeNull()
            // Every product, any stage, with neither; only the named ones with `only`.
            ->and(catalogSearches()->searchProducts(catalogSearchesStore(), null, false)->total)->toBe(4)
            ->and(array_map(static fn (FoundProductDto $row): string => $row->productId, catalogSearches()->searchProducts(catalogSearchesStore(), null, false, only: [$draft, $beta['product']])->rows))->toBe([$beta['product'], $draft])
            ->and(catalogSearches()->searchProducts(catalogSearchesStore(), null, true, only: [$draft])->total)->toBe(0);
    });

    it('finds a product by a name or by a code its sizes carry', function () {
        $drawer = catalogSearchesProduct('Drawer', 'درج');
        catalogSearchesProduct('Hinge', 'مفصلة');
        $code = (string) DB::table('catalog.variants')->where('product_id', $drawer['product'])->value('code');

        expect(array_map(static fn (FoundProductDto $row): string => $row->productId, catalogSearches()->searchProducts(catalogSearchesStore(), $code, true)->rows))->toBe([$drawer['product']])
            ->and(catalogSearches()->searchProducts(catalogSearchesStore(), 'درج', true)->total)->toBe(1);
    });

    it('answers a category\'s products and those of every category below it, any stage, by name', function () {
        $kitchens = Px::category('Kitchens');
        $drawers = Px::category('Drawers', $kitchens);
        $inside = Px::ready(['60 cm'], $drawers);
        DB::table('catalog.products')->where('id', $inside['product'])->update(['name_en' => 'Zeta']);
        $draft = Px::product('Alpha draft');
        DB::table('catalog.products')->where('id', $draft)->update(['category_id' => $kitchens, 'name_en' => 'Alpha']);
        Px::ready(['60 cm'], Px::category('Wardrobes'));

        $found = catalogSearches()->productsInCategory($kitchens, null);

        expect(array_map(static fn (FoundProductDto $row): string => $row->productId, $found->rows))->toBe([$draft, $inside['product']])
            ->and($found->total)->toBe(2)
            ->and(catalogSearches()->productsInCategory($drawers, null)->total)->toBe(1)
            ->and(catalogSearches()->productsInCategory($kitchens, 'zeta')->total)->toBe(1)
            ->and(catalogSearches()->productsInCategory('not-an-id', null)->total)->toBe(0)
            ->and(catalogSearches()->productsInCategory(strtolower((string) Str::ulid()), null)->total)->toBe(0);
    });
});

describe('variantIdsOfProducts', function () {
    it('answers many products\' sizes in their own order in one query, archived ones only when asked', function () {
        $drawer = catalogSearchesProduct('Drawer', 'درج', ['60 cm', '80 cm']);
        $hinge = catalogSearchesProduct('Hinge', 'مفصلة');
        Fx::asSystem(fn () => app(ArchiveVariantHandler::class)->handle(new ArchiveVariant($drawer['variants'][0])));
        DB::table('catalog.variants')->where('id', $drawer['variants'][0])->update(['position' => 9]);
        $queries = Cx::recordQueries();

        $sizes = catalogSearches()->variantIdsOfProducts([strtoupper($drawer['product']), $hinge['product'], 'not-an-id', strtolower((string) Str::ulid())]);

        expect(count($queries))->toBe(1)
            ->and($sizes)->toEqual([$drawer['product'] => [$drawer['variants'][1]], $hinge['product'] => $hinge['variants']])
            ->and(catalogSearches()->variantIdsOfProducts([$drawer['product']], true)[$drawer['product']])->toBe([$drawer['variants'][1], $drawer['variants'][0]])
            ->and(catalogSearches()->variantIdsOfProducts([]))->toBe([]);
    });
});

describe('variantBriefs and a search with no total (amendment 23(h))', function () {
    it('answers many sizes in brief in one query - product names and stage, place, archived - an unknown id left out', function () {
        $drawer = catalogSearchesProduct('Drawer', 'درج', ['60 cm', '80 cm']);
        $draft = Px::product('Draft');
        $draftSize = Px::variant($draft, '7300001');
        Fx::asSystem(fn () => app(ArchiveVariantHandler::class)->handle(new ArchiveVariant($drawer['variants'][0])));
        $queries = Cx::recordQueries();

        $briefs = catalogSearches()->variantBriefs([strtoupper($drawer['variants'][0]), $drawer['variants'][1], $draftSize, 'not-an-id', strtolower((string) Str::ulid())]);

        expect(count($queries))->toBe(1)
            ->and(array_keys($briefs))->toEqualCanonicalizing([$drawer['variants'][0], $drawer['variants'][1], $draftSize])
            ->and([$briefs[$drawer['variants'][0]]->isArchived, $briefs[$drawer['variants'][1]]->isArchived])->toBe([true, false])
            ->and([$briefs[$drawer['variants'][1]]->productId, $briefs[$drawer['variants'][1]]->productNameEn, $briefs[$drawer['variants'][1]]->productNameAr])->toBe([$drawer['product'], 'Drawer', 'درج'])
            ->and($briefs[$drawer['variants'][1]]->position)->toBeGreaterThan($briefs[$drawer['variants'][0]]->position)
            ->and([$briefs[$drawer['variants'][1]]->productStage, $briefs[$draftSize]->productStage])->toBe([ProductStage::Ready, ProductStage::Draft])
            ->and(catalogSearches()->variantBriefs([]))->toBe([]);
    });

    it('skips the count when asked: the same rows and cursor, the total 0, one query fewer', function () {
        $drawer = catalogSearchesProduct('Drawer', 'درج', ['60 cm', '80 cm']);
        $counted = Cx::recordQueries();
        $with = catalogSearches()->searchVariants(catalogSearchesStore(), null, $drawer['variants'], limit: 1);
        $withQueries = count($counted);
        $skipped = Cx::recordQueries();
        $without = catalogSearches()->searchVariants(catalogSearchesStore(), null, $drawer['variants'], limit: 1, withTotal: false);

        expect([catalogSearchesIds($without->rows), $without->next, $without->total])->toBe([catalogSearchesIds($with->rows), $with->next, 0])
            ->and($with->total)->toBe(2)
            ->and(count($skipped))->toBe($withQueries - 1);
    });
});

describe('categoriesWithPaths and wholesaleOf', function () {
    it('answers each category with the path from the top down to it, in the order asked, in one query', function () {
        $kitchens = Px::category('Kitchens');
        $drawers = Px::category('Drawers', $kitchens);
        $runners = Px::category('Runners', $drawers);
        DB::table('catalog.categories')->where('id', $drawers)->update(['is_active' => false]);
        $queries = Cx::recordQueries();

        $paths = catalogSearches()->categoriesWithPaths([strtoupper($runners), $kitchens, 'not-an-id', strtolower((string) Str::ulid())]);

        expect(count($queries))->toBe(1)
            ->and(array_keys($paths))->toBe([$runners, $kitchens])
            ->and(array_map(static fn (TranslatedTextDto $name): string => $name->en, $paths[$runners]->path))->toBe([
                (string) DB::table('catalog.categories')->where('id', $kitchens)->value('name_en'),
                (string) DB::table('catalog.categories')->where('id', $drawers)->value('name_en'),
                (string) DB::table('catalog.categories')->where('id', $runners)->value('name_en'),
            ])
            ->and($paths[$runners]->nameEn)->toBe((string) DB::table('catalog.categories')->where('id', $runners)->value('name_en'))
            ->and($paths[$runners]->isActive)->toBeTrue()
            ->and($paths[$kitchens]->path)->toHaveCount(1)
            ->and(catalogSearches()->categoriesWithPaths([]))->toBe([]);
    });

    it('answers each size\'s wholesale minimum, maximum and whether it sells wholesale in the store, in one query', function () {
        $drawer = catalogSearchesProduct('Drawer', 'درج', ['60 cm', '80 cm']);
        $elsewhere = catalogSearchesProduct('Hinge', 'مفصلة', store: 'eg');
        [$sixty, $eighty] = $drawer['variants'];
        $sa = Fx::storeId('sa');
        DB::table('catalog.store_products')->where('store_id', $sa)->where('product_id', $drawer['product'])->update(['wholesale_minimum' => 10, 'wholesale_maximum' => 500]);
        DB::table('catalog.store_variants')->where('store_id', $sa)->where('variant_id', $sixty)->update(['sells_wholesale' => true]);
        $queries = Cx::recordQueries();

        $terms = catalogSearches()->wholesaleOf(catalogSearchesStore(), [$sixty, strtoupper($eighty), $elsewhere['variants'][0], 'not-an-id']);

        expect(count($queries))->toBe(1)
            ->and(array_keys($terms))->toEqualCanonicalizing([$sixty, $eighty, $elsewhere['variants'][0]])
            ->and([$terms[$sixty]->minimum, $terms[$sixty]->maximum, $terms[$sixty]->soldWholesale])->toBe([10, 500, true])
            // Sold one piece at a time too (Pricing's "Wholesale Only" is the other way round).
            ->and([$terms[$sixty]->soldRetail, $terms[$elsewhere['variants'][0]]->soldRetail])->toBe([true, false])
            ->and([$terms[$eighty]->minimum, $terms[$eighty]->soldWholesale])->toBe([10, false])
            // Not chosen in this store: no terms there, not sold wholesale.
            ->and([$terms[$elsewhere['variants'][0]]->minimum, $terms[$elsewhere['variants'][0]]->maximum, $terms[$elsewhere['variants'][0]]->soldWholesale])->toBe([null, null, false])
            ->and(catalogSearches()->wholesaleOf(catalogSearchesStore(), []))->toBe([]);
    });
});
