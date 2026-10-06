<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Import\Imports;
use Modules\Catalog\Domain\Exception\ImportRefused;
use Modules\Catalog\Infrastructure\Eloquent\DatabaseImports;
use Modules\Catalog\Infrastructure\Import\DiskImportArchives;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogImports as Ix;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;

use function Pest\Laravel\seed;

/*
| Uploading a products file (catalog.md §1.12, amendment 6; the guide in docs/modules/catalog-import/):
| a Super Admin's; read and checked whole, against the catalog too, and refused with every problem
| listed — or kept as an import whose page lists each name the catalog lacks once, and each product
| whose code the catalog already has, for the Super Admin to decide. Nothing in the catalog changes.
| A zip is kept until its products are brought in, and its photos unpacked only into files of its own.
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
 * @return list<array{at: string, problem: string}>
 */
function catalogUploadProblems(string $path): array
{
    try {
        Ix::upload($path);
    } catch (ImportRefused $refused) {
        return $refused->problems;
    }

    throw new LogicException('The file was not refused.');
}

function catalogUploadExample(): string
{
    return (string) file_get_contents(dirname(__DIR__, 4).'/docs/modules/catalog-import/products.example.json');
}

/**
 * The guide's example, as a zip with its photos.
 */
function catalogUploadExampleZip(): string
{
    return Ix::zip([
        'products.json' => catalogUploadExample(),
        'photos/1304-45-zinc.jpg' => 'jpeg',
        'photos/1304-front.jpg' => 'jpeg',
        'photos/1304-open.webp' => 'webp',
        'photos/2001.png' => 'png',
    ]);
}

describe('who uploads', function () {
    it('is a Super Admin\'s: no role uploads, an admin holding every Catalog job included', function () {
        Fx::actAsAdmin(['*'], CatalogPermissions::jobs());

        expect(fn () => Ix::upload(Ix::temp(Ix::json([Ix::product('1')]))))->toThrow(Unauthorized::class);
        expect(DB::table('catalog.imports')->count())->toBe(0);
    });
});

describe('a file that passes', function () {
    it('becomes an import deciding, its products waiting as the file gave them, and nothing in the catalog changes', function () {
        $staffId = Fx::staff(superAdmin: true);
        Fx::actAsStaff($staffId);
        $products = DB::table('catalog.products')->count();
        $brands = DB::table('catalog.brands')->count();

        $id = Ix::upload(Ix::temp(Ix::json([
            Ix::product('1304', ['brand' => 'Tallsen', 'attribute_set' => 'Sizes', 'variants' => [['code' => '1304', 'values' => ['Width' => '60 cm']], ['code' => '1305', 'values' => ['Width' => '80 cm']]]]),
            Ix::product('2001'),
        ])), 'C:\\fakepath\\spring.json');

        $import = DB::table('catalog.imports')->where('id', $id)->first() ?? throw new LogicException('No import.');
        expect($import->kind)->toBe('PRODUCTS')
            ->and($import->state)->toBe('DECIDING')
            ->and($import->file_name)->toBe('spring.json')
            ->and($import->archive)->toBeNull()
            ->and($import->uploaded_by)->toBe($staffId);

        $rows = DB::table('catalog.import_products')->where('import_id', $id)->orderBy('number')->get();
        expect($rows->pluck('number')->all())->toBe([1, 2])
            ->and($rows->pluck('codes')->all())->toBe(['{1304,1305}', '{2001}'])
            ->and($rows->pluck('state')->unique()->all())->toBe(['WAITING'])
            ->and($rows->pluck('decision')->unique()->all())->toBe([null])
            ->and($rows->pluck('conflict_product_id')->unique()->all())->toBe([null]);
        $first = $rows->first() ?? throw new LogicException('No product.');
        expect(json_decode((string) $first->data, true))->toMatchArray(['number' => 1, 'name_ar' => 'منتج 1304', 'brand' => 'Tallsen']);

        expect(DB::table('catalog.products')->count())->toBe($products)
            ->and(DB::table('catalog.brands')->count())->toBe($brands)
            ->and(Fx::audits('catalog.import.added', $id))->toBe(1);
    });

    it('lists each name the catalog lacks once, with how many products use it', function () {
        $id = Ix::upload(Ix::temp(Ix::json([
            Ix::product('1', ['brand' => 'Tallsen', 'category' => 'Kitchens / Drawers', 'warranty' => 'Two years', 'attribute_set' => 'Sizes', 'variants' => [
                ['code' => '1', 'values' => ['Width' => '60 cm'], 'details' => ['Material' => ['ar' => 'فولاذ', 'en' => 'Steel']]],
                ['code' => '1', 'values' => ['Width' => '80 cm']],
            ], 'filters' => ['Use' => ['Kitchen']]]),
            Ix::product('2', ['brand' => 'TALLSEN', 'category' => 'kitchens / Hinges', 'attribute_set' => 'sizes', 'variants' => [
                ['code' => '2', 'values' => ['width' => '60 CM']],
            ]]),
        ])));

        expect(Ix::names($id))->toBe([
            'ATTRIBUTE' => ['Material', 'Use', 'Width'],
            'BRAND' => ['Tallsen'],
            'CATEGORY' => ['Kitchens', 'Kitchens / Drawers', 'kitchens / Hinges'],
            'SET' => ['Sizes'],
            'VALUE' => ['60 cm', '80 cm', 'Kitchen'],
            'WARRANTY' => ['Two years'],
        ]);

        $row = fn (string $written): stdClass => DB::table('catalog.import_names')->where('import_id', $id)->where('written', $written)->first() ?? throw new LogicException("No {$written}.");
        expect($row('Tallsen')->products)->toBe(2)
            ->and($row('Kitchens')->products)->toBe(2)
            ->and($row('Kitchens / Drawers')->products)->toBe(1)
            ->and($row('60 cm')->products)->toBe(2)
            ->and($row('60 cm')->attribute)->toBe('Width')
            ->and($row('Width')->attribute_kind)->toBe('VARIANT')
            ->and($row('Use')->attribute_kind)->toBe('FILTERABLE')
            ->and($row('Material')->attribute_kind)->toBe('INFORMATIONAL')
            ->and(DB::table('catalog.import_names')->where('import_id', $id)->pluck('decision')->unique()->all())->toBe([null]);
    });

    it('finds the names the catalog has, in either language, as search compares words', function () {
        $brand = Px::brand('Blum');
        $kitchens = Px::category('Kitchens');
        $drawers = Px::category('Drawers', $kitchens);
        $width = Px::attribute('Width');
        Px::value($width, '60 cm');
        $set = Px::set([$width]);
        $names = fn (string $table, string $id): array => (array) DB::table("catalog.{$table}")->where('id', $id)->first(['name_ar', 'name_en']);
        [$brandAr, $brandEn] = array_values($names('brands', $brand));
        [$kitchensAr] = array_values($names('categories', $kitchens));
        [, $drawersEn] = array_values($names('categories', $drawers));
        [, $widthEn] = array_values($names('attributes', $width));
        [, $setEn] = array_values($names('attribute_sets', $set));

        $id = Ix::upload(Ix::temp(Ix::json([
            Ix::product('1', ['brand' => mb_strtoupper((string) $brandEn), 'category' => "{$kitchensAr} / ".strtolower((string) $drawersEn), 'attribute_set' => (string) $setEn, 'variants' => [
                ['code' => '1', 'values' => [strtoupper((string) $widthEn) => '60 CM']],
                ['code' => '1', 'values' => [(string) $widthEn => '80 cm']],
            ]]),
            Ix::product('2', ['brand' => (string) $brandAr]),
        ])));

        expect(Ix::names($id))->toBe(['VALUE' => ['80 cm']]);
    });

    it('finds a brand by its fixed number, and lists a number the catalog lacks apart from names', function () {
        $blum = Px::brand('Blum');
        // Numbers come from a sequence a rolled-back test does not wind back: read them, never assume them.
        $number = (int) DB::table('catalog.brands')->where('id', $blum)->value('number');
        $touchWood = (int) DB::table('catalog.brands')->where('is_default', true)->value('number');
        $missing = $number + 1000;

        $id = Ix::uploadProducts([Ix::product('1', ['brand' => $number]), Ix::product('2', ['brand' => $touchWood]), Ix::product('3', ['brand' => $missing]), Ix::product('4', ['brand' => (string) $missing])]);

        expect(Ix::names($id))->toBe(['BRAND' => ["#{$missing}", (string) $missing]]);
    });

    it('lists only the levels of a category path the catalog lacks, each under its parent', function () {
        $kitchens = Px::category('Kitchens');
        $kitchensEn = (string) DB::table('catalog.categories')->where('id', $kitchens)->value('name_en');

        $id = Ix::upload(Ix::temp(Ix::json([Ix::product('1', ['category' => "{$kitchensEn} / Drawers / Runners"])])));

        expect(Ix::names($id))->toBe(['CATEGORY' => ["{$kitchensEn} / Drawers", "{$kitchensEn} / Drawers / Runners"]]);
    });

    it('names the catalog\'s product already holding a code, for the Super Admin to decide', function () {
        $ready = Px::ready();
        $code = (string) DB::table('catalog.variants')->where('id', $ready['variants'][0])->value('code');

        $id = Ix::upload(Ix::temp(Ix::json([Ix::product($code), Ix::product('77')])));

        $rows = DB::table('catalog.import_products')->where('import_id', $id)->orderBy('number')->pluck('conflict_product_id')->all();
        expect($rows)->toBe([$ready['product'], null]);
    });
});

describe('what refuses the file', function () {
    it('refuses a category path ending at a category with sub-categories', function () {
        $kitchens = Px::category('Kitchens');
        Px::category('Drawers', $kitchens);
        $kitchensEn = (string) DB::table('catalog.categories')->where('id', $kitchens)->value('name_en');

        expect(catalogUploadProblems(Ix::temp(Ix::json([Ix::product('1', ['category' => $kitchensEn])]))))
            ->toBe([['at' => 'product 1 › category', 'problem' => "a category with no sub-categories: {$kitchensEn} has some"]]);
    });

    it('refuses an attribute used for two jobs in the file, once however many products repeat it', function () {
        $problems = catalogUploadProblems(Ix::temp(Ix::json([
            Ix::product('1', ['attribute_set' => 'Sizes', 'variants' => [['code' => '1', 'values' => ['Width' => '60 cm']]]]),
            Ix::product('2', ['filters' => ['width' => ['60 cm']]]),
            Ix::product('3', ['filters' => ['Width' => ['80 cm']]]),
        ])));

        expect($problems)->toBe([['at' => 'product 2 › filters › width', 'problem' => 'width in one place only — values, filters or details — as in product 1: an attribute has one job']]);
    });

    it('refuses an attribute used for a job the catalog gives it another', function () {
        $use = Px::attribute('Use', 'FILTERABLE');
        $useEn = (string) DB::table('catalog.attributes')->where('id', $use)->value('name_en');

        $problems = catalogUploadProblems(Ix::temp(Ix::json([
            Ix::product('1', ['variants' => [['code' => '1', 'details' => [$useEn => 3]]]]),
        ])));

        expect($problems)->toBe([['at' => "product 1 › variants 1 › details › {$useEn}", 'problem' => "{$useEn} in filters, where the catalog uses it: an attribute has one job"]]);
    });

    it('refuses variants whose values do not match the catalog\'s set', function () {
        $width = Px::attribute('Width');
        $finish = Px::attribute('Finish');
        $other = Px::attribute('Depth');
        $set = Px::set([$width, $finish]);
        $en = fn (string $table, string $id): string => (string) DB::table("catalog.{$table}")->where('id', $id)->value('name_en');

        $problems = catalogUploadProblems(Ix::temp(Ix::json([
            Ix::product('1', ['attribute_set' => $en('attribute_sets', $set), 'variants' => [['code' => '1', 'values' => [$en('attributes', $width) => '60 cm', $en('attributes', $other) => '5 cm']]]]),
            Ix::product('2', ['attribute_set' => $en('attribute_sets', $set), 'variants' => [['code' => '2', 'values' => [$en('attributes', $width) => '60 cm']]]]),
        ])));

        expect($problems)->toBe([
            ['at' => 'product 1 › variants › values › '.$en('attributes', $other), 'problem' => 'an attribute of the set '.$en('attribute_sets', $set)],
            ['at' => 'product 2 › variants › values', 'problem' => 'one value for each of the '.$en('attribute_sets', $set).' set\'s 2 attributes'],
        ]);
    });

    it('refuses a new set given different attributes by two products', function () {
        $problems = catalogUploadProblems(Ix::temp(Ix::json([
            Ix::product('1', ['attribute_set' => 'Sizes', 'variants' => [['code' => '1', 'values' => ['Width' => '60 cm', 'Finish' => 'Zinc']]]]),
            Ix::product('2', ['attribute_set' => 'sizes', 'variants' => [['code' => '2', 'values' => ['finish' => 'Zinc', 'width' => '80 cm']]]]),
            Ix::product('3', ['attribute_set' => 'Sizes', 'variants' => [['code' => '3', 'values' => ['Width' => '60 cm']]]]),
        ])));

        expect($problems)->toBe([['at' => 'product 3 › attribute_set', 'problem' => 'the same attributes in every product for the new set Sizes: product 1 gives it others']]);
    });

    it('refuses a product whose codes two of the catalog\'s products hold', function () {
        $first = Px::ready();
        $second = Px::ready();
        $code = fn (array $ready): string => (string) DB::table('catalog.variants')->where('id', $ready['variants'][0])->value('code');

        $problems = catalogUploadProblems(Ix::temp(Ix::json([
            Ix::product('1', ['attribute_set' => 'Sizes', 'variants' => [['code' => $code($first), 'values' => ['Width' => '1']], ['code' => $code($second), 'values' => ['Width' => '2']]]]),
        ])));

        expect($problems)->toHaveCount(1)
            ->and($problems[0]['at'])->toBe('product 1 › variants')
            ->and($problems[0]['problem'])->toContain($code($first).' and '.$code($second).' belong to 2 different products');
    });

    it('lists every problem at once, and keeps nothing', function () {
        $problems = catalogUploadProblems(Ix::zip([
            'products.json' => Ix::json([
                Ix::product('1', ['photos' => ['a.gif']]),
                Ix::product('2', ['photos' => ['b.jpg']]),
            ]),
            'a.gif' => 'gif',
            'b.jpg' => str_repeat("\0", 10 * 1024 * 1024 + 1),
        ]));

        expect($problems)->toBe([
            ['at' => 'product 1 › photos', 'problem' => 'a.gif: a JPEG, PNG or WebP photo'],
            ['at' => 'product 2 › photos', 'problem' => 'b.jpg: at most 10 MB'],
        ]);
        expect(DB::table('catalog.imports')->count())->toBe(0)
            ->and(Storage::disk('local')->allFiles())->toBe([]);
    });

    it('refuses a JSON over 20 MB before reading it', function () {
        expect(catalogUploadProblems(Ix::temp(str_repeat(' ', 20 * 1024 * 1024 + 1))))
            ->toBe([['at' => 'file', 'problem' => 'a JSON file of at most 20 MB, or a zip holding it']]);
    });
});

describe('a zip', function () {
    it('reads its products.json, checks its photos and keeps it until the products are brought in', function () {
        $id = Ix::upload(catalogUploadExampleZip(), 'spring.zip');

        $archive = (string) DB::table('catalog.imports')->where('id', $id)->value('archive');
        expect($archive)->toBe("catalog-imports/{$id}.zip");
        Storage::disk('local')->assertExists($archive);

        // The hinge names brand number 2: only TouchWood, number 1, is here.
        expect(Ix::names($id))->toBe([
            'ATTRIBUTE' => ['Closing', 'Finish', 'Length', 'Load', 'Material', 'Use'],
            'BRAND' => ['#2'],
            'CATEGORY' => ['Handles', 'Kitchens', 'Kitchens / Drawers', 'Kitchens / Drawers / Runners', 'Tallsen', 'Tallsen / Hinges'],
            'SET' => ['Runner sizes'],
            'VALUE' => ['45 cm', '50 cm', 'Black', 'Kitchen', 'Soft-close', 'Wardrobe', 'Zinc'],
            'WARRANTY' => ['Two years'],
        ]);
        expect(DB::table('catalog.import_products')->where('import_id', $id)->count())->toBe(3);
    });

    it('refuses a zip whose products.json is not at its top', function () {
        expect(catalogUploadProblems(Ix::zip(['files/products.json' => Ix::json([Ix::product('1')])])))
            ->toBe([['at' => 'file', 'problem' => 'a products.json of at most 20 MB at the top of the zip']]);
    });

    it('lets the kept zip go when the import cannot be written', function () {
        $real = app(DatabaseImports::class);
        $imports = Mockery::mock(Imports::class);
        $imports->shouldReceive('nextId')->andReturnUsing(fn (): string => $real->nextId());
        $imports->shouldReceive('codeHolders')->andReturnUsing(fn (array $codes): array => $real->codeHolders(array_values(array_map('strval', $codes))));
        $imports->shouldReceive('addProductsImport')->andThrow(new RuntimeException('The database went away.'));
        app()->instance(Imports::class, $imports);

        expect(fn () => Ix::upload(catalogUploadExampleZip()))->toThrow(RuntimeException::class, 'The database went away.');
        expect(Storage::disk('local')->allFiles())->toBe([]);
    });

    it('unpacks only the photos asked for, into temporary files of its own naming, and forgets the zip', function () {
        [$archives, $directory] = catalogUploadArchives();
        $kept = $archives->keep('01jaaaaaaaaaaaaaaaaaaaaaaa', Ix::zip(['products.json' => '{}', '../outside.png' => 'png', 'photos/a.jpg' => 'jpeg', 'photos/b.jpg' => 'other']));

        $files = $archives->unpack($kept, ['photos/a.jpg', '../outside.png']);

        expect(array_keys($files))->toBe(['photos/a.jpg', '../outside.png'])
            ->and(file_get_contents($files['photos/a.jpg']))->toBe('jpeg')
            ->and(file_get_contents($files['../outside.png']))->toBe('png')
            ->and(array_map(fn (string $file): string => (string) realpath(dirname($file)), array_values($files)))->toBe([$directory, $directory])
            ->and(catalogUploadDirectory($directory))->toHaveCount(2);

        $archives->release($files);
        $archives->forget($kept);

        expect(catalogUploadDirectory($directory))->toBe([])
            ->and(file_exists(dirname($directory).DIRECTORY_SEPARATOR.'outside.png'))->toBeFalse()
            ->and(Storage::disk('local')->allFiles())->toBe([]);
    });

    it('refuses to unpack a photo the kept zip does not hold, leaving no file behind', function () {
        [$archives, $directory] = catalogUploadArchives();
        $kept = $archives->keep('01jaaaaaaaaaaaaaaaaaaaaaab', Ix::zip(['products.json' => '{}', 'photos/a.jpg' => 'jpeg']));

        expect(fn () => $archives->unpack($kept, ['photos/a.jpg', 'photos/missing.jpg']))->toThrow(LogicException::class, 'photos/missing.jpg is not in the kept zip');
        expect(catalogUploadDirectory($directory))->toBe([]);
    });
});

/**
 * The archives over a temporary directory of the test's own, so what they leave there is seen.
 *
 * @return array{DiskImportArchives, string}
 */
function catalogUploadArchives(): array
{
    $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tw-archives-'.bin2hex(random_bytes(4));
    mkdir($directory);
    $directory = (string) realpath($directory);
    Ix::remember($directory);

    return [new DiskImportArchives(app(Factory::class), 'local', $directory), $directory];
}

/**
 * @return list<string>
 */
function catalogUploadDirectory(string $directory): array
{
    return array_values(array_filter(glob($directory.DIRECTORY_SEPARATOR.'*') ?: [], 'is_file'));
}
