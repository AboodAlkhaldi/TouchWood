<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Command\BringInImport\BringInImport;
use Modules\Catalog\Application\Command\BringInImport\BringInImportHandler;
use Modules\Catalog\Application\Command\BringInImportProducts\BringInImportProducts;
use Modules\Catalog\Application\Command\BringInImportProducts\BringInImportProductsHandler;
use Modules\Catalog\Application\Command\DecideImportCodes\DecideImportCodes;
use Modules\Catalog\Application\Command\DecideImportCodes\DecideImportCodesHandler;
use Modules\Catalog\Domain\Exception\ImportClosed;
use Modules\Catalog\Domain\Exception\ImportUndecided;
use Modules\Catalog\Infrastructure\Queue\BringInImportJob;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogFixtures as Cx;
use Tests\Modules\Catalog\Support\CatalogImports as Ix;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;

use function Pest\Laravel\seed;

/*
| Bringing an import's products in (catalog.md §1.12, page part 3; amendments 6, 7): the confirm asks
| the names and codes again and starts it only when nothing waits; from the queue, one transaction —
| the products' lock first, then the lists' — makes the names decided "create it", then each product
| through the product handlers: created as a draft with its photos, updated, replaced, skipped, held
| back, or made with new codes. Any refusal rolls everything back, and the page says where and why.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    Storage::fake('local');
    Storage::fake('public');
    Queue::fake();
    Fx::actAsStaff(Fx::staff(superAdmin: true));
});

afterEach(function () {
    Ix::cleanUp();
});

/**
 * Every name of the import decided: those given by what they are written as, the rest refused.
 *
 * @param  array<string, array<string, string>>  $decisions  written => decision
 */
function catalogBringDecide(string $importId, array $decisions = []): void
{
    Ix::decideNames($importId, $decisions);
}

/**
 * @return array<string, string>
 */
function catalogBringCreate(string $ar, string $en): array
{
    return Ix::create($ar, $en);
}

/** The confirm, then the queued work as the queue runs it. */
function catalogBringIn(string $importId): void
{
    Ix::bringIn($importId);
}

/**
 * @return array<string, mixed>
 */
function catalogBringRow(string $importId, int $number): array
{
    return (array) (DB::table('catalog.import_products')->where('import_id', $importId)->where('number', $number)->first(['state', 'product_id']) ?? throw new LogicException('No product.'));
}

function catalogBringEnglish(string $table, string $id): string
{
    return (string) DB::table("catalog.{$table}")->where('id', $id)->value('name_en');
}

describe('the confirm', function () {
    it('is a Super Admin\'s', function () {
        $import = Ix::uploadProducts([Ix::product('1')]);
        Fx::actAsAdmin(['*'], CatalogPermissions::jobs());

        expect(fn () => app(BringInImportHandler::class)->handle(new BringInImport($import)))->toThrow(Unauthorized::class);
    });

    it('starts bringing in once nothing waits: audited and queued', function () {
        $import = Ix::uploadProducts([Ix::product('1')]);

        app(BringInImportHandler::class)->handle(new BringInImport($import));

        expect(DB::table('catalog.imports')->where('id', $import)->value('state'))->toBe('BRINGING_IN')
            ->and(Fx::audits('catalog.import.bringing_in', $import))->toBe(1)
            ->and(fn () => app(BringInImportHandler::class)->handle(new BringInImport($import)))->toThrow(ImportClosed::class);
        Queue::assertPushed(BringInImportJob::class, fn (BringInImportJob $job): bool => $job->importId === $import);
    });

    it('asks the names and codes again, keeps what it found, and says how many wait', function () {
        $import = Ix::uploadProducts([Ix::product('77', ['brand' => 'Blumm', 'warranty' => 'Two yeers'])]);
        // Since the file came: the catalog gained a product holding its code.
        $held = Px::product();
        Px::variant($held, '77');

        try {
            app(BringInImportHandler::class)->handle(new BringInImport($import));
        } catch (ImportUndecided $undecided) {
        }

        expect([$undecided->names ?? null, $undecided->codes ?? null])->toBe([2, 1])
            ->and(DB::table('catalog.import_products')->where('import_id', $import)->value('conflict_product_id'))->toBe($held)
            ->and(DB::table('catalog.imports')->where('id', $import)->value('state'))->toBe('DECIDING');
        Queue::assertNothingPushed();
    });
});

describe('bringing in', function () {
    it('makes the names decided "create it", then each product as a draft through the product handlers', function () {
        $length = Px::attribute('Length');
        $finish = Px::attribute('Finish');
        $load = Px::attribute('Load', 'INFORMATIONAL');
        $use = Px::attribute('Use', 'FILTERABLE');
        $kitchen = Px::value($use, 'Kitchen');
        $blum = Px::brand('Blum');
        $blumNumber = (int) DB::table('catalog.brands')->where('id', $blum)->value('number');
        [$lengthEn, $finishEn, $loadEn, $useEn] = [catalogBringEnglish('attributes', $length), catalogBringEnglish('attributes', $finish), catalogBringEnglish('attributes', $load), catalogBringEnglish('attributes', $use)];
        $text = "# Runner\nQuiet.";

        $import = Ix::uploadProducts([
            Ix::product('1304', [
                'description' => ['ar' => $text, 'en' => $text],
                'brand' => $blumNumber,
                'category' => 'Kitchens / Drawers / Runners',
                'attribute_set' => 'Runner sizes',
                'variants' => [
                    ['code' => '1304', 'values' => [$lengthEn => '45 cm', $finishEn => 'Zinc'], 'details' => [$loadEn => 35], 'weight_g' => 1450],
                    ['code' => '1314', 'values' => [$lengthEn => '50 cm', $finishEn => 'Zinc']],
                ],
                'search_words' => ['سحاب'],
                'filters' => [$useEn => ['Kitchen', 'Wardrobe']],
            ]),
            Ix::product('2001', ['brand' => 'Hettich']),
            Ix::product('3001', ['category' => 'Handles']),
        ]);
        catalogBringDecide($import, [
            'Kitchens' => catalogBringCreate('مطابخ', 'Kitchens'),
            'Kitchens / Drawers' => catalogBringCreate('أدراج', 'Drawers'),
            'Kitchens / Drawers / Runners' => catalogBringCreate('مجاري', 'Runners'),
            'Runner sizes' => catalogBringCreate('مقاسات المجاري', 'Runner sizes'),
            '45 cm' => catalogBringCreate('45 سم', '45 cm'),
            '50 cm' => catalogBringCreate('50 سم', '50 cm'),
            'Zinc' => catalogBringCreate('زنك', 'Zinc'),
        ]);

        catalogBringIn($import);

        expect(DB::table('catalog.imports')->where('id', $import)->value('state'))->toBe('IN')
            ->and(array_column([catalogBringRow($import, 1), catalogBringRow($import, 2), catalogBringRow($import, 3)], 'state'))->toBe(['IN', 'IN', 'IN'])
            ->and(Fx::audits('catalog.import.brought_in', $import))->toBe(1);

        $runner = (string) catalogBringRow($import, 1)['product_id'];
        $product = DB::table('catalog.products')->where('id', $runner)->first() ?? throw new LogicException('No product.');
        $runners = (string) DB::table('catalog.categories')->where('name_en', 'Runners')->value('id');
        $drawers = (string) DB::table('catalog.categories')->where('id', $runners)->value('parent_id');
        expect([$product->stage, $product->brand_id, $product->category_id])->toBe(['DRAFT', $blum, $runners])
            ->and(catalogBringEnglish('categories', $drawers))->toBe('Drawers')
            ->and(catalogBringEnglish('categories', (string) DB::table('catalog.categories')->where('id', $drawers)->value('parent_id')))->toBe('Kitchens')
            ->and(catalogBringEnglish('attribute_sets', (string) $product->attribute_set_id))->toBe('Runner sizes')
            ->and(DB::table('catalog.variants')->where('product_id', $runner)->orderBy('position')->pluck('weight_grams')->all())->toBe([1450, null])
            ->and(DB::table('catalog.variants')->where('product_id', $runner)->orderBy('position')->pluck('code')->all())->toBe(['1304', '1314'])
            ->and((float) DB::table('catalog.variant_details')->where('attribute_id', $load)->value('number'))->toBe(35.0)
            ->and(DB::table('catalog.product_search_words')->where('product_id', $runner)->pluck('word')->all())->toBe(['سحاب'])
            ->and(DB::table('catalog.product_filter_values')->where('product_id', $runner)->where('attribute_id', $use)->pluck('value_id')->all())->toBe([$kitchen]);

        // Refused: the brand is the default brand; the category is left out.
        $default = DB::table('catalog.brands')->where('is_default', true)->value('id');
        expect(DB::table('catalog.products')->where('id', catalogBringRow($import, 2)['product_id'])->value('brand_id'))->toBe($default)
            ->and(DB::table('catalog.products')->where('id', catalogBringRow($import, 3)['product_id'])->value('category_id'))->toBeNull();
    });

    it('adds a zip\'s photos to the media library, and lets the zip go', function () {
        $import = Ix::upload(Ix::zip([
            'products.json' => Ix::json([Ix::product('1', ['photos' => ['front.jpg', 'side.png'], 'variants' => [['code' => '1', 'photos' => ['front.jpg']]]])]),
            'front.jpg' => Ix::image('front.jpg', 11),
            'side.png' => Ix::image('side.png', 12),
        ]));
        $archive = (string) DB::table('catalog.imports')->where('id', $import)->value('archive');

        catalogBringIn($import);

        $product = (string) catalogBringRow($import, 1)['product_id'];
        $gallery = DB::table('catalog.product_photos')->where('product_id', $product)->orderBy('position')->pluck('media_id')->all();
        $variant = (string) DB::table('catalog.variants')->where('product_id', $product)->value('id');
        expect($gallery)->toHaveCount(2)
            ->and(DB::table('catalog.variant_photos')->where('variant_id', $variant)->pluck('media_id')->all())->toBe([$gallery[0]])
            ->and(DB::table('platform.media')->whereIn('id', $gallery)->pluck('visibility')->unique()->all())->toBe(['PUBLIC'])
            ->and(DB::table('catalog.imports')->where('id', $import)->value('archive'))->toBeNull();
        Storage::disk('local')->assertMissing($archive);
    });

    it('skips, holds back, or gives new codes, as decided', function () {
        $first = Px::ready();
        $second = Px::ready();
        $code = fn (array $ready): string => (string) DB::table('catalog.variants')->where('id', $ready['variants'][0])->value('code');
        $width = catalogBringEnglish('attributes', $first['width']);
        $import = Ix::uploadProducts([
            Ix::product($code($first)),
            Ix::product($code($second)),
            Ix::product('9100', ['attribute_set' => 'Sizes', 'variants' => [['code' => '9100', 'values' => [$width => '99 cm']]]]),
            Ix::product('9200'),
        ]);
        catalogBringDecide($import);
        app(DecideImportCodesHandler::class)->handle(new DecideImportCodes($import, [
            ['product_id' => Ix::productId($import, 1), 'decision' => 'SKIP'],
            ['product_id' => Ix::productId($import, 2), 'decision' => 'RECODE', 'new_codes' => [$code($second) => '9300']],
        ]));

        catalogBringIn($import);

        expect(array_column([catalogBringRow($import, 1), catalogBringRow($import, 2), catalogBringRow($import, 3), catalogBringRow($import, 4)], 'state'))->toBe(['SKIPPED', 'IN', 'HELD', 'IN'])
            ->and(catalogBringRow($import, 1)['product_id'])->toBeNull()
            ->and(DB::table('catalog.variants')->where('product_id', catalogBringRow($import, 2)['product_id'])->pluck('code')->all())->toBe(['9300'])
            ->and(DB::table('catalog.product_codes')->where('code', '9100')->exists())->toBeFalse();
    });

    it('updates the catalog\'s product with what the file gives, keeping the rest', function () {
        $ready = Px::ready(['60 cm']);
        $code = (string) DB::table('catalog.variants')->where('id', $ready['variants'][0])->value('code');
        $width = catalogBringEnglish('attributes', $ready['width']);
        $set = catalogBringEnglish('attribute_sets', (string) DB::table('catalog.products')->where('id', $ready['product'])->value('attribute_set_id'));
        $gallery = DB::table('catalog.product_photos')->where('product_id', $ready['product'])->pluck('media_id')->all();
        $import = Ix::uploadProducts([Ix::product($code, ['name' => ['ar' => 'مجرى محدث'], 'attribute_set' => $set, 'variants' => [
            ['code' => $code, 'values' => [$width => '60 cm'], 'weight_g' => 999],
            ['code' => '7314', 'values' => [$width => '80 cm']],
        ]])]);
        catalogBringDecide($import, ['80 cm' => catalogBringCreate('80 سم', '80 cm')]);
        app(DecideImportCodesHandler::class)->handle(new DecideImportCodes($import, [['product_id' => Ix::productId($import, 1), 'decision' => 'UPDATE']]));

        catalogBringIn($import);

        $product = DB::table('catalog.products')->where('id', $ready['product'])->first() ?? throw new LogicException('No product.');
        expect([catalogBringRow($import, 1)['state'], catalogBringRow($import, 1)['product_id']])->toBe(['UPDATED', $ready['product']])
            ->and([$product->name_ar, $product->stage])->toBe(['مجرى محدث', 'READY'])
            ->and($product->name_en)->not->toBeNull()
            ->and(DB::table('catalog.variants')->where('id', $ready['variants'][0])->value('weight_grams'))->toBe(999)
            ->and(DB::table('catalog.variants')->where('product_id', $ready['product'])->orderBy('position')->pluck('code')->all())->toBe([$code, '7314'])
            ->and(DB::table('catalog.product_photos')->where('product_id', $ready['product'])->pluck('media_id')->all())->toBe($gallery);
    });

    it('matches the catalog product\'s variants by code: a code it carries updates that variant, its values too (P33)', function () {
        $ready = Px::ready(['60 cm', '80 cm']);
        [$sixty, $eighty] = $ready['variants'];
        $codes = DB::table('catalog.variants')->whereIn('id', [$sixty, $eighty])->pluck('code', 'id');
        $width = catalogBringEnglish('attributes', $ready['width']);
        $set = catalogBringEnglish('attribute_sets', (string) DB::table('catalog.products')->where('id', $ready['product'])->value('attribute_set_id'));
        $import = Ix::uploadProducts([Ix::product((string) $codes[$sixty], ['attribute_set' => $set, 'variants' => [
            ['code' => (string) $codes[$sixty], 'values' => [$width => '90 cm']],
            ['code' => (string) $codes[$eighty], 'values' => [$width => '80 cm'], 'weight_g' => 800],
        ]])]);
        catalogBringDecide($import, ['90 cm' => catalogBringCreate('90 سم', '90 cm')]);
        app(DecideImportCodesHandler::class)->handle(new DecideImportCodes($import, [['product_id' => Ix::productId($import, 1), 'decision' => 'UPDATE']]));

        catalogBringIn($import);

        $ninety = (string) DB::table('catalog.attribute_values')->where('attribute_id', $ready['width'])->where('name_en', '90 cm')->value('id');
        expect(catalogBringRow($import, 1)['state'])->toBe('UPDATED')
            ->and(DB::table('catalog.variants')->where('product_id', $ready['product'])->count())->toBe(2)
            ->and(DB::table('catalog.variant_values')->where('variant_id', $sixty)->pluck('value_id')->all())->toBe([$ninety])
            ->and(DB::table('catalog.variants')->where('id', $eighty)->value('weight_grams'))->toBe(800);
    });

    it('moves sizes between the codes a ready product keeps in whatever the file\'s order, asking nothing at the confirm', function () {
        $ready = Px::ready(['60 cm', '80 cm']);
        [$sixty, $eighty] = $ready['variants'];
        $codes = DB::table('catalog.variants')->whereIn('id', [$sixty, $eighty])->pluck('code', 'id');
        $width = catalogBringEnglish('attributes', $ready['width']);
        $set = catalogBringEnglish('attribute_sets', (string) DB::table('catalog.products')->where('id', $ready['product'])->value('attribute_set_id'));
        // Listed first, 60 cm's code takes 80 cm, which 80 cm's code is leaving for 90 cm: written the other way round.
        $import = Ix::uploadProducts([Ix::product((string) $codes[$sixty], ['attribute_set' => $set, 'variants' => [
            ['code' => (string) $codes[$sixty], 'values' => [$width => '80 cm']],
            ['code' => (string) $codes[$eighty], 'values' => [$width => '90 cm']],
        ]])]);
        catalogBringDecide($import, ['90 cm' => catalogBringCreate('90 سم', '90 cm')]);
        app(DecideImportCodesHandler::class)->handle(new DecideImportCodes($import, [['product_id' => Ix::productId($import, 1), 'decision' => 'UPDATE']]));

        catalogBringIn($import);

        $value = static fn (string $variant): string => (string) DB::table('catalog.attribute_values')->where('id', DB::table('catalog.variant_values')->where('variant_id', $variant)->value('value_id'))->value('name_en');
        expect(catalogBringRow($import, 1)['state'])->toBe('UPDATED')
            ->and([$value($sixty), $value($eighty)])->toBe(['80 cm', '90 cm'])
            ->and(DB::table('catalog.variants')->whereIn('id', [$sixty, $eighty])->pluck('code', 'id')->all())->toEqual($codes->all());
    });

    it('stops at sizes that only change places, naming the product, and keeps nothing', function () {
        $ready = Px::ready(['60 cm', '80 cm']);
        [$sixty, $eighty] = $ready['variants'];
        $codes = DB::table('catalog.variants')->whereIn('id', [$sixty, $eighty])->pluck('code', 'id');
        $width = catalogBringEnglish('attributes', $ready['width']);
        $set = catalogBringEnglish('attribute_sets', (string) DB::table('catalog.products')->where('id', $ready['product'])->value('attribute_set_id'));
        $import = Ix::uploadProducts([Ix::product((string) $codes[$sixty], ['attribute_set' => $set, 'variants' => [
            ['code' => (string) $codes[$sixty], 'values' => [$width => '80 cm']],
            ['code' => (string) $codes[$eighty], 'values' => [$width => '60 cm']],
        ]])]);
        app(DecideImportCodesHandler::class)->handle(new DecideImportCodes($import, [['product_id' => Ix::productId($import, 1), 'decision' => 'UPDATE']]));

        catalogBringIn($import);

        $state = DB::table('catalog.imports')->where('id', $import)->first(['state', 'failure']) ?? throw new LogicException('No import.');
        expect($state->state)->toBe('FAILED')
            ->and((string) $state->failure)->toStartWith('product 1: ')
            ->and(DB::table('catalog.variant_values')->where('variant_id', $sixty)->value('value_id'))->toBe(DB::table('catalog.attribute_values')->where('attribute_id', $ready['width'])->where('name_en', '60 cm')->value('id'));
    });

    it('replaces a draft whole: what the file leaves out cleared, its variants the file does not name archived', function () {
        $product = Px::product();
        $variant = Px::variant($product, '4400');
        DB::table('catalog.product_search_words')->insert(['product_id' => $product, 'normalized' => 'old', 'word' => 'old', 'position' => 0]);
        $import = Ix::uploadProducts([Ix::product('4400', ['variants' => [['code' => '4400', 'weight_g' => 10]]])]);
        app(DecideImportCodesHandler::class)->handle(new DecideImportCodes($import, [['product_id' => Ix::productId($import, 1), 'decision' => 'REPLACE']]));

        catalogBringIn($import);

        expect(catalogBringRow($import, 1)['state'])->toBe('REPLACED')
            ->and(DB::table('catalog.variants')->where('id', $variant)->value('weight_grams'))->toBe(10)
            ->and(DB::table('catalog.product_search_words')->where('product_id', $product)->count())->toBe(0);
    });

    it('keeps nothing when a product is refused, and says which and why', function () {
        $ready = Px::ready();
        $code = (string) DB::table('catalog.variants')->where('id', $ready['variants'][0])->value('code');
        $products = DB::table('catalog.products')->count();
        $media = DB::table('platform.media')->count();
        $import = Ix::upload(Ix::zip([
            'products.json' => Ix::json([
                Ix::product('5100', ['photos' => ['a.jpg']]),
                // Replaced whole by a product with no photo and no category, a ready one cannot stay ready.
                Ix::product($code),
            ]),
            'a.jpg' => Ix::image('a.jpg', 13),
        ]));
        app(DecideImportCodesHandler::class)->handle(new DecideImportCodes($import, [['product_id' => Ix::productId($import, 2), 'decision' => 'REPLACE']]));

        catalogBringIn($import);

        $state = DB::table('catalog.imports')->where('id', $import)->first(['state', 'failure', 'archive']) ?? throw new LogicException('No import.');
        expect($state->state)->toBe('FAILED')
            ->and((string) $state->failure)->toStartWith('product 2: ')
            ->and($state->archive)->not->toBeNull()
            ->and(DB::table('catalog.products')->count())->toBe($products)
            ->and(DB::table('platform.media')->count())->toBe($media)
            // The photo added to the media library before the refusal leaves no file behind either.
            ->and(Storage::disk('public')->allFiles())->toBe([])
            ->and(DB::table('catalog.import_products')->where('import_id', $import)->pluck('state')->unique()->all())->toBe(['WAITING'])
            ->and(Fx::audits('catalog.import.failed', $import))->toBe(1);
    });

    it('takes the products\' lock first, then the lists\', inside its one transaction', function () {
        $import = Ix::uploadProducts([Ix::product('1')]);
        app(BringInImportHandler::class)->handle(new BringInImport($import));
        $locks = Cx::recordLocks();

        Fx::asSystem(fn () => app(BringInImportProductsHandler::class)->handle(new BringInImportProducts($import)));

        expect(array_slice(array_column($locks->getArrayCopy(), 'key'), 0, 5))->toBe(['catalog:products', 'catalog:categories', 'catalog:brands', 'catalog:attributes', 'catalog:warranties'])
            ->and(array_unique(array_column(array_slice($locks->getArrayCopy(), 0, 5), 'level')))->toBe([2]);
    });

    it('does nothing for an import not bringing in', function () {
        $import = Ix::uploadProducts([Ix::product('1')]);

        Fx::asSystem(fn () => app(BringInImportProductsHandler::class)->handle(new BringInImportProducts($import)));

        expect(DB::table('catalog.imports')->where('id', $import)->value('state'))->toBe('DECIDING')
            ->and(DB::table('catalog.product_codes')->where('code', '1')->exists())->toBeFalse();
    });
});
