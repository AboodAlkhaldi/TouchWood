<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Command\AddLabel\AddLabel;
use Modules\Catalog\Application\Command\AddLabel\AddLabelHandler;
use Modules\Catalog\Application\Command\AddWordPair\AddWordPair;
use Modules\Catalog\Application\Command\AddWordPair\AddWordPairHandler;
use Modules\Catalog\Application\Command\AttachLabels\AttachLabels;
use Modules\Catalog\Application\Command\AttachLabels\AttachLabelsHandler;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Catalog\Application\Command\RankCategories\RankCategories;
use Modules\Catalog\Application\Command\RankCategories\RankCategoriesHandler;
use Modules\Catalog\Application\Import\DescriptionText;
use Modules\Catalog\Application\Query\ListAttributes\ListAttributes;
use Modules\Catalog\Application\Query\ListAttributes\ListAttributesHandler;
use Modules\Catalog\Application\Query\ListBrands\ListBrands;
use Modules\Catalog\Application\Query\ListBrands\ListBrandsHandler;
use Modules\Catalog\Application\Query\ListCategories\ListCategories;
use Modules\Catalog\Application\Query\ListCategories\ListCategoriesHandler;
use Modules\Catalog\Application\Query\ListLabels\ListLabels;
use Modules\Catalog\Application\Query\ListLabels\ListLabelsHandler;
use Modules\Catalog\Application\Query\Lists\AttributeRow;
use Modules\Catalog\Application\Query\Lists\BrandRow;
use Modules\Catalog\Application\Query\Lists\CategoryRow;
use Modules\Catalog\Application\Query\Lists\LabelRow;
use Modules\Catalog\Application\Query\Lists\WarrantyRow;
use Modules\Catalog\Application\Query\Lists\WordPairRow;
use Modules\Catalog\Application\Query\ListSearchesWithNoResults\ListSearchesWithNoResults;
use Modules\Catalog\Application\Query\ListSearchesWithNoResults\ListSearchesWithNoResultsHandler;
use Modules\Catalog\Application\Query\ListWarranties\ListWarranties;
use Modules\Catalog\Application\Query\ListWarranties\ListWarrantiesHandler;
use Modules\Catalog\Application\Query\ListWordPairs\ListWordPairs;
use Modules\Catalog\Application\Query\ListWordPairs\ListWordPairsHandler;
use Modules\Catalog\Application\Query\ProductsReached\ProductsReached;
use Modules\Catalog\Application\Query\ProductsReached\ProductsReachedHandler;
use Modules\Catalog\Application\Query\ViewAttribute\ViewAttribute;
use Modules\Catalog\Application\Query\ViewAttribute\ViewAttributeHandler;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogFixtures as Cx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;

use function Pest\Laravel\seed;

/*
| The reads the panel's list screens stand on (catalog.md §4.4 S1–S7): what each answers, who may read
| it — the list's job in any store (P2) — and who may change it — the job with All stores —, and that a
| list is read in a fixed number of queries, never one per row (frontend.md §5). Platform's read of many
| photos at once (§2.4), and the description's plain text both ways (P1).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
});

/**
 * How many queries a read makes.
 */
function catalogListReadsQueries(callable $read): int
{
    // Each read as its own request, as production serves it: what a request remembers - the stores,
    // the settings - is forgotten here, or a read would count fewer queries than a page pays
    // (access.md amendment 65). The actor stays: that is a binding.
    app()->forgetScopedInstances();
    $queries = Cx::recordQueries();
    $read();

    return count($queries);
}

/**
 * The recorded queries that read Catalog's own tables, written either way - `catalog.x` in a raw
 * select, `"catalog"."x"` from the query builder (lesson 109).
 *
 * @param  ArrayObject<int, array{sql: string, bindings: array<array-key, mixed>, level: int}>  $queries
 * @return list<string>
 */
function catalogListReadsCatalogSql(ArrayObject $queries): array
{
    return array_values(array_map(
        fn (array $query): string => $query['sql'],
        array_filter($queries->getArrayCopy(), fn (array $query): bool => preg_match('/"?catalog"?\."?[a-z_]+/i', $query['sql']) === 1),
    ));
}

describe('who may read a shared list, and who may change it (P2)', function () {
    it('lets the list\'s job in one store read it, and only the job with All stores change it', function () {
        Px::brand('Hettich');
        Cx::actAsStaffWith([CatalogPermissions::BRAND_MANAGE], ['sa']);

        $list = app(ListBrandsHandler::class)->handle(new ListBrands);

        expect($list->brands)->not->toBeEmpty()
            ->and($list->mayChange)->toBeFalse();

        Cx::actAsStaffWith([CatalogPermissions::BRAND_MANAGE], ['*']);

        expect(app(ListBrandsHandler::class)->handle(new ListBrands)->mayChange)->toBeTrue();
    });

    it('refuses someone holding none of the list\'s jobs, before anything is read', function (Closure $read) {
        Cx::actAsStaffWith([CatalogPermissions::PRODUCT_VIEW], ['*']);
        $queries = Cx::recordQueries();

        expect(fn () => $read())->toThrow(Unauthorized::class)
            ->and(catalogListReadsCatalogSql($queries))->toBe([]);

        // The same read by someone who may make it does reach the tables: the filter above sees them.
        Cx::actAsStaffWith([
            CatalogPermissions::BRAND_MANAGE, CatalogPermissions::CATEGORY_MANAGE, CatalogPermissions::ATTRIBUTE_MANAGE,
            CatalogPermissions::LABEL_MANAGE, CatalogPermissions::WARRANTY_MANAGE, CatalogPermissions::SEARCH_WORD_MANAGE,
        ], ['*']);
        $allowed = Cx::recordQueries();
        $read();

        expect(catalogListReadsCatalogSql($allowed))->not->toBe([]);
    })->with([
        'brands' => [fn () => app(ListBrandsHandler::class)->handle(new ListBrands)],
        'categories' => [fn () => app(ListCategoriesHandler::class)->handle(new ListCategories)],
        'attributes' => [fn () => app(ListAttributesHandler::class)->handle(new ListAttributes)],
        'labels' => [fn () => app(ListLabelsHandler::class)->handle(new ListLabels)],
        'warranties' => [fn () => app(ListWarrantiesHandler::class)->handle(new ListWarranties)],
        'word pairs' => [fn () => app(ListWordPairsHandler::class)->handle(new ListWordPairs)],
    ]);
});

describe('the brands', function () {
    it('lists each brand with its fixed number, its addresses, and how many products carry it', function () {
        $brand = Px::brand('Hettich');
        Px::product('Hinge', $brand);
        Px::product('Slide', $brand);
        Cx::actAsStaffWith([CatalogPermissions::BRAND_MANAGE]);

        $rows = app(ListBrandsHandler::class)->handle(new ListBrands)->brands;
        $row = collect($rows)->firstOrFail(fn (BrandRow $row): bool => $row->id === $brand);

        expect($row->number)->toBe((int) DB::table('catalog.brands')->where('id', $brand)->value('number'))
            ->and($row->slugEn)->toStartWith('hettich')
            ->and($row->slugAr)->not->toBeNull()
            ->and($row->products)->toBe(2)
            ->and($row->agencyType)->toBe('DISTRIBUTOR');
    });

    it('reads the whole list in the same number of queries, however many brands there are', function () {
        Px::brand('One');
        Cx::actAsStaffWith([CatalogPermissions::BRAND_MANAGE]);
        // Warm first: the reader's permissions are read once and kept in the cache.
        app(ListBrandsHandler::class)->handle(new ListBrands);
        $few = catalogListReadsQueries(fn () => app(ListBrandsHandler::class)->handle(new ListBrands));

        foreach (range(1, 6) as $n) {
            Px::brand("More {$n}");
        }

        $many = catalogListReadsQueries(fn () => app(ListBrandsHandler::class)->handle(new ListBrands));

        expect($few)->toBeGreaterThan(0)->and($many)->toBe($few);
    });
});

describe('the categories', function () {
    it('lists the tree with each category\'s own products and a store\'s place beside the base store\'s', function () {
        $top = Px::category('Kitchens');
        $low = Px::category('Drawers', $top);
        Fx::asSystem(fn () => app(RankCategoriesHandler::class)->handle(new RankCategories(Fx::storeId('eg'), [$top => 7])));
        Cx::actAsStaffWith([CatalogPermissions::CATEGORY_RANK], ['eg']);

        $list = app(ListCategoriesHandler::class)->handle(new ListCategories(Fx::storeId('eg')));
        $topRow = collect($list->categories)->firstOrFail(fn (CategoryRow $row): bool => $row->id === $top);
        $lowRow = collect($list->categories)->firstOrFail(fn (CategoryRow $row): bool => $row->id === $low);

        expect($topRow->storeRank)->toBe(7)
            ->and($topRow->baseRank)->toBe(0)
            ->and($lowRow->parentId)->toBe($top)
            ->and($lowRow->storeRank)->toBe(0)
            ->and($list->mayRank)->toBeTrue()
            ->and($list->mayManage)->toBeFalse();
    });

    it('refuses a store whose menu the reader does not order, as not allowed', function () {
        Cx::actAsStaffWith([CatalogPermissions::CATEGORY_RANK], ['eg']);

        expect(fn () => app(ListCategoriesHandler::class)->handle(new ListCategories(Fx::storeId('sa'))))->toThrow(Unauthorized::class);
    });

    it('shows someone who only changes the tree the base store\'s order, with nothing to rank', function () {
        Px::category('Kitchens');
        Cx::actAsStaffWith([CatalogPermissions::CATEGORY_MANAGE]);

        $list = app(ListCategoriesHandler::class)->handle(new ListCategories);

        expect($list->categories)->not->toBeEmpty()
            ->and($list->storeId)->toBeNull()
            ->and($list->mayRank)->toBeFalse()
            ->and($list->mayManage)->toBeTrue();
    });
});

describe('the attributes', function () {
    it('says what locks an attribute\'s job and what uses it, and lists one attribute\'s values', function () {
        $width = Px::attribute('Width');
        $sixty = Px::value($width, '60 cm');
        $empty = Px::attribute('Finish', 'FILTERABLE');
        Cx::actAsStaffWith([CatalogPermissions::ATTRIBUTE_MANAGE]);

        $rows = collect(app(ListAttributesHandler::class)->handle(new ListAttributes)->attributes);
        $widthRow = $rows->firstOrFail(fn (AttributeRow $row): bool => $row->id === $width);
        $emptyRow = $rows->firstOrFail(fn (AttributeRow $row): bool => $row->id === $empty);

        expect($widthRow->kindLocked)->toBeTrue()
            ->and($widthRow->values)->toBe(1)
            ->and($widthRow->inUse)->toBeFalse()
            ->and($emptyRow->kindLocked)->toBeFalse();

        Px::variantAttributes(Px::product(), [$width]);
        $view = app(ViewAttributeHandler::class)->handle(new ViewAttribute($width));

        expect($view->attribute->inProducts)->toBeTrue()
            ->and($view->attribute->inUse)->toBeTrue()
            ->and(array_map(fn ($value) => $value->id, $view->values))->toBe([$sixty]);
    });

    it('answers an attribute that does not exist as not found', function () {
        Cx::actAsStaffWith([CatalogPermissions::ATTRIBUTE_MANAGE]);

        expect(fn () => app(ViewAttributeHandler::class)->handle(new ViewAttribute('01k0000000000000000000zzzz')))->toThrow(ListItemNotFound::class);
    });
});

describe('the labels, warranties and word pairs', function () {
    it('says a value is in use once a variant carries it', function () {
        ['width' => $width] = Px::ready(['60 cm']);
        Cx::actAsStaffWith([CatalogPermissions::ATTRIBUTE_MANAGE]);

        $values = app(ViewAttributeHandler::class)->handle(new ViewAttribute($width))->values;

        expect($values)->toHaveCount(1)
            ->and($values[0]->inUse)->toBeTrue();
    });

    it('lists the labels, the warranties and the word pairs, each with how many products use it', function () {
        $warranty = Px::warranty();
        $unused = Px::warranty();
        ['product' => $product] = Px::ready();
        $label = Fx::asSystem(fn (): string => app(AddLabelHandler::class)->handle(new AddLabel('جديد', 'New', 'green', 10)));
        $idle = Fx::asSystem(fn (): string => app(AddLabelHandler::class)->handle(new AddLabel('عرض', 'Offer', 'amber', 20)));

        // One product carrying the label in two stores is one product using it.
        foreach (['sa', 'eg'] as $store) {
            Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId($store), $product, true)));
            Fx::asSystem(fn () => app(AttachLabelsHandler::class)->handle(new AttachLabels(Fx::storeId($store), $product, [$label])));
        }

        DB::table('catalog.products')->where('id', $product)->update(['warranty_id' => $warranty]);
        Fx::asSystem(fn () => app(AddWordPairHandler::class)->handle(new AddWordPair('مفصلة', 'hinge')));
        Cx::actAsStaffWith([CatalogPermissions::LABEL_MANAGE, CatalogPermissions::WARRANTY_MANAGE, CatalogPermissions::SEARCH_WORD_MANAGE]);

        $warranties = collect(app(ListWarrantiesHandler::class)->handle(new ListWarranties)->warranties);
        $labels = collect(app(ListLabelsHandler::class)->handle(new ListLabels)->labels);
        $pairs = app(ListWordPairsHandler::class)->handle(new ListWordPairs)->pairs;

        expect($warranties->firstOrFail(fn (WarrantyRow $row): bool => $row->id === $warranty)->periodMonths)->toBe(24)
            ->and($warranties->firstOrFail(fn (WarrantyRow $row): bool => $row->id === $warranty)->products)->toBe(1)
            ->and($warranties->firstOrFail(fn (WarrantyRow $row): bool => $row->id === $unused)->products)->toBe(0)
            ->and($labels->map(fn (LabelRow $row): array => [$row->id, $row->tone, $row->products])->all())->toBe([[$label, 'green', 1], [$idle, 'amber', 0]])
            ->and(array_map(fn (WordPairRow $row): array => [$row->wordA, $row->wordB], $pairs))->toBe([['hinge', 'مفصله']]); // kept as search compares words: ة read as ه
    });
});

describe('the searches that found nothing (P11)', function () {
    it('groups the last twelve months\' searches that found nothing, the most searched first', function () {
        $sa = Fx::storeId('sa');
        $eg = Fx::storeId('eg');
        $now = now();
        DB::table('catalog.search_log')->insert([
            ['store_id' => $sa, 'locale' => 'ar', 'query' => 'مفصله', 'results' => 0, 'searched_at' => $now->copy()->subDays(2)],
            ['store_id' => $sa, 'locale' => 'ar', 'query' => 'مفصله', 'results' => 0, 'searched_at' => $now->copy()->subDay()],
            ['store_id' => $eg, 'locale' => 'en', 'query' => 'rail', 'results' => 0, 'searched_at' => $now->copy()->subDay()],
            ['store_id' => $sa, 'locale' => 'en', 'query' => 'hinge', 'results' => 4, 'searched_at' => $now],
            ['store_id' => $sa, 'locale' => 'en', 'query' => 'old', 'results' => 0, 'searched_at' => $now->copy()->subMonths(13)],
        ]);
        Cx::actAsStaffWith([CatalogPermissions::SEARCH_WORD_MANAGE]);

        $all = app(ListSearchesWithNoResultsHandler::class)->handle(new ListSearchesWithNoResults);
        $egypt = app(ListSearchesWithNoResultsHandler::class)->handle(new ListSearchesWithNoResults($eg));

        expect(array_map(fn ($row) => [$row->query, $row->times], $all->searches))->toBe([['مفصله', 2], ['rail', 1]])
            ->and($all->more)->toBeFalse()
            ->and(array_map(fn ($row) => $row->query, $egypt->searches))->toBe(['rail']);
    });

    it('says whether another page follows, without counting every group', function () {
        $sa = Fx::storeId('sa');
        DB::table('catalog.search_log')->insert(array_map(fn (int $n): array => ['store_id' => $sa, 'locale' => 'en', 'query' => "word {$n}", 'results' => 0, 'searched_at' => now()], range(1, 3)));
        Cx::actAsStaffWith([CatalogPermissions::SEARCH_WORD_MANAGE]);

        $first = app(ListSearchesWithNoResultsHandler::class)->handle(new ListSearchesWithNoResults(null, 1, 2));
        $second = app(ListSearchesWithNoResultsHandler::class)->handle(new ListSearchesWithNoResults(null, 2, 2));

        expect(count($first->searches))->toBe(2)->and($first->more)->toBeTrue()
            ->and(count($second->searches))->toBe(1)->and($second->more)->toBeFalse();
    });

    it('needs the job with All stores (§3)', function () {
        Cx::actAsStaffWith([CatalogPermissions::SEARCH_WORD_MANAGE], ['sa']);

        expect(fn () => app(ListSearchesWithNoResultsHandler::class)->handle(new ListSearchesWithNoResults))->toThrow(Unauthorized::class);
    });

    it('refuses a store id that is not one, never reading it as every store', function () {
        DB::table('catalog.search_log')->insert(['store_id' => Fx::storeId('sa'), 'locale' => 'en', 'query' => 'rail', 'results' => 0, 'searched_at' => now()]);
        Cx::actAsStaffWith([CatalogPermissions::SEARCH_WORD_MANAGE]);

        expect(fn () => app(ListSearchesWithNoResultsHandler::class)->handle(new ListSearchesWithNoResults('not a store')))->toThrow(Unauthorized::class)
            ->and(app(ListSearchesWithNoResultsHandler::class)->handle(new ListSearchesWithNoResults)->searches)->toHaveCount(1);
    });
});

describe('what a deactivation reaches', function () {
    it('answers every product of a brand, and every product in a category and under it, in any stage', function () {
        $brand = Px::brand('Tallsen');
        $mine = Px::product('Hinge', $brand);
        Px::product('Other');
        $top = Px::category('Kitchens');
        $low = Px::category('Drawers', $top);
        $ready = Px::ready(['60 cm'], $low)['product'];
        Cx::actAsStaffWith([CatalogPermissions::BRAND_MANAGE, CatalogPermissions::CATEGORY_MANAGE]);

        expect(array_map(fn ($row) => $row->id, app(ProductsReachedHandler::class)->handle(new ProductsReached(ProductsReached::BRAND, $brand))))->toBe([$mine])
            ->and(array_map(fn ($row) => [$row->id, $row->stage], app(ProductsReachedHandler::class)->handle(new ProductsReached(ProductsReached::CATEGORY, $top))))->toBe([[$ready, 'READY']]);
    });

    it('is read only by someone who may deactivate: the job with All stores', function () {
        $brand = Px::brand('Tallsen');
        Cx::actAsStaffWith([CatalogPermissions::BRAND_MANAGE], ['sa']);

        expect(fn () => app(ProductsReachedHandler::class)->handle(new ProductsReached(ProductsReached::BRAND, $brand)))->toThrow(Unauthorized::class);
    });
});

describe('Platform\'s read of many photos at once (catalog.md §2.4)', function () {
    it('answers the addresses of a page of photos in one query, leaving out what names no media', function () {
        $photos = [Cx::media(), Cx::media(), Cx::media()];
        $queries = Cx::recordQueries();

        $urls = app(PlatformApi::class)->mediaUrlsOf([...$photos, '01k0000000000000000000zzzz', 'not an id']);

        expect(array_keys($urls))->toEqualCanonicalizing($photos)
            ->and(count(collect($queries)->filter(fn (array $query): bool => str_contains($query['sql'], 'platform') && str_contains($query['sql'], 'media'))))->toBe(1)
            ->and(app(PlatformApi::class)->mediaUrlsOf([]))->toBe([]);
    });
});

describe('a description as plain text (P1)', function () {
    it('writes the structured text back as the marks it is read from, and reads it again the same', function () {
        $text = "# Fitting\n\nA **soft** close drawer.\n\n- 60 cm\n- 80 cm";
        $document = DescriptionText::document($text);

        expect(DescriptionText::text($document))->toBe($text)
            ->and(DescriptionText::document(DescriptionText::text($document)))->toBe($document)
            ->and(DescriptionText::text(null))->toBe('');
    });
});
