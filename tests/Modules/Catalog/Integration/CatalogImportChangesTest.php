<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Command\DeactivateWarranty\DeactivateWarranty;
use Modules\Catalog\Application\Command\DeactivateWarranty\DeactivateWarrantyHandler;
use Modules\Catalog\Application\Command\DecideImportNames\DecideImportNames;
use Modules\Catalog\Application\Command\DecideImportNames\DecideImportNamesHandler;
use Modules\Catalog\Application\Command\SetImportedBrand\SetImportedBrand;
use Modules\Catalog\Application\Command\SetImportedBrand\SetImportedBrandHandler;
use Modules\Catalog\Application\Command\SetImportedCategory\SetImportedCategory;
use Modules\Catalog\Application\Command\SetImportedCategory\SetImportedCategoryHandler;
use Modules\Catalog\Application\Command\SetImportedFilters\SetImportedFilters;
use Modules\Catalog\Application\Command\SetImportedFilters\SetImportedFiltersHandler;
use Modules\Catalog\Application\Command\SetImportedSearchWords\SetImportedSearchWords;
use Modules\Catalog\Application\Command\SetImportedSearchWords\SetImportedSearchWordsHandler;
use Modules\Catalog\Application\Command\SetImportedWarranty\SetImportedWarranty;
use Modules\Catalog\Application\Command\SetImportedWarranty\SetImportedWarrantyHandler;
use Modules\Catalog\Domain\Exception\BrandNotFound;
use Modules\Catalog\Domain\Exception\CategoryNotLowest;
use Modules\Catalog\Domain\Exception\ImportClosed;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\ListItemInactive;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogImports as Ix;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;

use function Pest\Laravel\seed;

/*
| Changing an import's products before they are brought in (catalog.md §1.12, amendment 7(c), (d),
| 8(a)): a Super Admin's; the brand, warranty or category, search words and filter values, for all the
| products or the selected — replacing what they have, or only filling the ones that have none (lists
| may also be added to), kept apart where the file gives none and given when brought in. "All of this
| just draft": the file's own is kept, nothing reaches the catalog, the names list follows the
| products, and each change is audited once.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    Storage::fake('local');
    Fx::actAsStaff(Fx::staff(superAdmin: true));
});

afterEach(function () {
    Ix::cleanUp();
});

/**
 * The product at this place in the import, as it will come in.
 *
 * @return array<string, mixed>
 */
function catalogChanged(string $importId, int $number): array
{
    $row = DB::table('catalog.import_products')->where('import_id', $importId)->where('number', $number)->first(['data', 'edited']) ?? throw new LogicException('No product.');

    /** @var array<string, mixed> */
    return json_decode((string) ($row->edited ?? $row->data), true, 512, JSON_THROW_ON_ERROR);
}

/**
 * The product at this place in the import, as the file gave it.
 *
 * @return array<string, mixed>
 */
function catalogChangedFile(string $importId, int $number): array
{
    /** @var array<string, mixed> */
    return json_decode((string) DB::table('catalog.import_products')->where('import_id', $importId)->where('number', $number)->value('data'), true, 512, JSON_THROW_ON_ERROR);
}

describe('who changes', function () {
    it('is a Super Admin\'s: no role changes an import\'s products, an admin holding every Catalog job included', function () {
        $import = Ix::uploadProducts([Ix::product('1')]);
        Fx::actAsAdmin(['*'], CatalogPermissions::jobs());

        expect(fn () => app(SetImportedSearchWordsHandler::class)->handle(new SetImportedSearchWords($import, null, ['درج'], 'ADD')))->toThrow(Unauthorized::class);
        expect(catalogChanged($import, 1)['search_words'])->toBe([]);
    });
});

describe('the brand', function () {
    it('replaces every product\'s brand by its fixed number, keeps the file\'s own, and drops the names no product uses', function () {
        $blum = Px::brand('Blum');
        $number = (int) DB::table('catalog.brands')->where('id', $blum)->value('number');
        $import = Ix::uploadProducts([Ix::product('1', ['brand' => 'Blumm']), Ix::product('2')]);

        $changed = app(SetImportedBrandHandler::class)->handle(new SetImportedBrand($import, null, $blum, 'REPLACE'));

        expect($changed)->toBe(2)
            ->and([catalogChanged($import, 1)['brand'], catalogChanged($import, 1)['brand_number']])->toBe([null, $number])
            ->and(catalogChanged($import, 2)['brand_number'])->toBe($number)
            ->and(catalogChangedFile($import, 1)['brand'])->toBe('Blumm')
            ->and(Ix::names($import))->toBe([])
            ->and(Fx::audits('catalog.import.edited', $import))->toBe(1)
            ->and(DB::table('catalog.products')->count())->toBe(0);
    });

    it('fills only the products the file gave none, when asked', function () {
        $blum = Px::brand('Blum');
        $import = Ix::uploadProducts([Ix::product('1', ['brand' => 'Blumm']), Ix::product('2')]);

        expect(app(SetImportedBrandHandler::class)->handle(new SetImportedBrand($import, null, $blum, 'FILL_EMPTY')))->toBe(1)
            ->and(catalogChanged($import, 1)['brand'])->toBe('Blumm')
            ->and(Ix::names($import))->toBe(['BRAND' => ['Blumm']]);
    });

    it('takes an active brand only', function () {
        $import = Ix::uploadProducts([Ix::product('1')]);

        expect(fn () => app(SetImportedBrandHandler::class)->handle(new SetImportedBrand($import, null, Px::warranty(), 'REPLACE')))->toThrow(BrandNotFound::class)
            ->and(fn () => app(SetImportedBrandHandler::class)->handle(new SetImportedBrand($import, null, Px::brand(), 'SOMETIMES')))->toThrow(InvalidCatalogAttribute::class, 'Invalid mode: REPLACE or FILL_EMPTY');
    });
});

describe('the products changed', function () {
    it('are the selected ones, of this import, or all', function () {
        $brand = Px::brand();
        $import = Ix::uploadProducts([Ix::product('1'), Ix::product('2'), Ix::product('3')]);
        $other = Ix::uploadProducts([Ix::product('4')]);

        expect(app(SetImportedBrandHandler::class)->handle(new SetImportedBrand($import, [strtoupper(Ix::productId($import, 2))], $brand, 'REPLACE')))->toBe(1)
            ->and(catalogChanged($import, 1)['brand_number'])->toBeNull()
            ->and(catalogChanged($import, 2)['brand_number'])->not->toBeNull()
            ->and(fn () => app(SetImportedBrandHandler::class)->handle(new SetImportedBrand($import, [Ix::productId($other, 1)], $brand, 'REPLACE')))->toThrow(ListItemNotFound::class)
            ->and(fn () => app(SetImportedBrandHandler::class)->handle(new SetImportedBrand($import, [], $brand, 'REPLACE')))->toThrow(InvalidCatalogAttribute::class, 'product_ids');
    });

    it('change nothing and record nothing when nothing would change', function () {
        $import = Ix::uploadProducts([Ix::product('1', ['search_words' => ['درج']])]);

        expect(app(SetImportedSearchWordsHandler::class)->handle(new SetImportedSearchWords($import, null, ['درج'], 'ADD')))->toBe(0)
            ->and(DB::table('catalog.import_products')->where('import_id', $import)->value('edited'))->toBeNull()
            ->and(Fx::audits('catalog.import.edited', $import))->toBe(0);
    });

    it('wait while the products are brought in or once they are, and reopen a failed bringing in', function () {
        $import = Ix::uploadProducts([Ix::product('1')]);
        $change = fn () => app(SetImportedSearchWordsHandler::class)->handle(new SetImportedSearchWords($import, null, ['درج'], 'ADD'));

        DB::table('catalog.imports')->where('id', $import)->update(['state' => 'BRINGING_IN']);
        expect($change)->toThrow(ImportClosed::class);

        DB::table('catalog.imports')->where('id', $import)->update(['state' => 'FAILED', 'failure' => 'Product 1: no.']);
        $change();
        expect((array) DB::table('catalog.imports')->where('id', $import)->first(['state', 'failure']))->toBe(['state' => 'DECIDING', 'failure' => null]);
    });

    it('keep the decisions on the names still used, with their new counts', function () {
        $import = Ix::uploadProducts([Ix::product('1', ['category' => 'Hinges', 'brand' => 'Blumm']), Ix::product('2', ['category' => 'Hinges'])]);
        $hinges = Ix::nameId($import, 'CATEGORY', 'Hinges');
        app(DecideImportNamesHandler::class)->handle(new DecideImportNames($import, [['name_id' => $hinges, 'decision' => 'CREATE', 'name_ar' => 'مفصلات', 'name_en' => 'Hinges']]));

        app(SetImportedCategoryHandler::class)->handle(new SetImportedCategory($import, [Ix::productId($import, 2)], Px::category(), 'REPLACE'));

        expect((array) DB::table('catalog.import_names')->where('id', $hinges)->first(['decision', 'products']))->toBe(['decision' => 'CREATE', 'products' => 1])
            ->and(Ix::names($import))->toBe(['BRAND' => ['Blumm'], 'CATEGORY' => ['Hinges']]);
    });
});

describe('the warranty and the category', function () {
    it('kept by id: an active warranty, an active category with no sub-categories', function () {
        $warranty = Px::warranty();
        $import = Ix::uploadProducts([Ix::product('1', ['warranty' => 'Two yeers', 'category' => 'Kitchens / Drawers'])]);
        $kitchens = Px::category('Kitchens');
        $drawers = Px::category('Drawers', $kitchens);

        expect(fn () => app(SetImportedCategoryHandler::class)->handle(new SetImportedCategory($import, null, $kitchens, 'REPLACE')))->toThrow(CategoryNotLowest::class);

        app(SetImportedWarrantyHandler::class)->handle(new SetImportedWarranty($import, null, $warranty, 'REPLACE'));
        app(SetImportedCategoryHandler::class)->handle(new SetImportedCategory($import, null, $drawers, 'REPLACE'));

        expect(array_intersect_key(catalogChanged($import, 1), array_flip(['warranty', 'warranty_id', 'category', 'category_id'])))
            // jsonb keeps an object's keys in its own order: compared by content.
            ->toEqual(['category' => null, 'warranty' => null, 'warranty_id' => $warranty, 'category_id' => $drawers])
            ->and(Ix::names($import))->toBe([]);

        Fx::asSystem(fn () => app(DeactivateWarrantyHandler::class)->handle(new DeactivateWarranty($warranty)));
        expect(fn () => app(SetImportedWarrantyHandler::class)->handle(new SetImportedWarranty($import, null, $warranty, 'REPLACE')))->toThrow(ListItemInactive::class);
    });
});

describe('search words and filter values', function () {
    it('are added, replaced, or given only to the products that have none — kept apart where the file gives none', function () {
        $import = Ix::uploadProducts([Ix::product('1', ['search_words' => ['سحاب']]), Ix::product('2'), Ix::product('3')]);
        $words = fn (array $words, string $mode, ?array $only = null) => app(SetImportedSearchWordsHandler::class)->handle(new SetImportedSearchWords($import, $only, $words, $mode));
        $kept = static fn (int $number): array => array_map(static fn (string $key): mixed => catalogChanged($import, $number)[$key], ['search_words', 'added_search_words', 'fill_search_words']);

        $words(['مجرى', 'سَحاب'], 'ADD', [Ix::productId($import, 1), Ix::productId($import, 2)]);
        // The file gives none: what it has is known when brought in, so what is added waits apart.
        expect($kept(1))->toBe([['سحاب', 'مجرى'], [], []])
            ->and($kept(2))->toBe([[], ['مجرى', 'سَحاب'], []]);

        expect($words(['درج'], 'FILL_EMPTY'))->toBe(1)
            ->and($kept(2))->toBe([[], ['مجرى', 'سَحاب'], []])
            ->and($kept(3))->toBe([[], [], ['درج']]);

        $words(['درج'], 'REPLACE');
        expect([$kept(1), $kept(2), $kept(3)])->toBe([[['درج'], [], []], [['درج'], [], []], [['درج'], [], []]]);
    });

    it('name a product they would take past its limit, and change none', function () {
        $import = Ix::uploadProducts([Ix::product('1', ['search_words' => array_map(static fn (int $n): string => "word{$n}", range(1, 30))]), Ix::product('2')]);

        expect(fn () => app(SetImportedSearchWordsHandler::class)->handle(new SetImportedSearchWords($import, null, ['extra'], 'ADD')))
            ->toThrow(InvalidCatalogAttribute::class, 'Invalid product 1 › search_words: at most 30');
        expect(DB::table('catalog.import_products')->where('import_id', $import)->whereNotNull('edited')->count())->toBe(0);
    });

    it('take values of active filter attributes, kept by id beside the file\'s, which a replace clears', function () {
        $use = Px::attribute('Use', 'FILTERABLE');
        $kitchen = Px::value($use, 'Kitchen');
        $width = Px::value(Px::attribute('Width'), '60 cm');
        $import = Ix::uploadProducts([Ix::product('1', ['filters' => ['Closing' => ['Soft-close']]]), Ix::product('2')]);
        $filters = fn (array $ids, string $mode) => app(SetImportedFiltersHandler::class)->handle(new SetImportedFilters($import, null, $ids, $mode));

        expect(fn () => $filters([$width], 'ADD'))->toThrow(InvalidCatalogAttribute::class, 'values of filter attributes');

        $kept = static fn (int $number): array => array_map(static fn (string $key): mixed => catalogChanged($import, $number)[$key], ['filter_value_ids', 'added_filter_value_ids', 'fill_filter_value_ids']);

        $filters([$kitchen], 'FILL_EMPTY');
        expect([$kept(1), $kept(2)])->toBe([[[], [], []], [[], [], [$kitchen]]]);

        $filters([$kitchen], 'ADD');
        expect([catalogChanged($import, 1)['filters'], catalogChanged($import, 1)['filter_value_ids']])->toBe([['Closing' => ['Soft-close']], [$kitchen]])
            ->and($kept(2))->toBe([[], [$kitchen], [$kitchen]])
            ->and(Ix::names($import))->toBe(['ATTRIBUTE' => ['Closing'], 'VALUE' => ['Soft-close']]);

        $filters([$kitchen], 'REPLACE');
        expect([catalogChanged($import, 1)['filters'], catalogChanged($import, 1)['filter_value_ids']])->toBe([[], [$kitchen]])
            ->and($kept(2))->toBe([[$kitchen], [], []])
            ->and(Ix::names($import))->toBe([]);
    });
});
