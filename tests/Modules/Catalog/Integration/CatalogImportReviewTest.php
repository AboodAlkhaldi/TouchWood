<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Contracts\Filesystem\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Command\AcceptImportedProducts\AcceptImportedProducts;
use Modules\Catalog\Application\Command\AcceptImportedProducts\AcceptImportedProductsHandler;
use Modules\Catalog\Application\Command\AddVariant\AddVariant;
use Modules\Catalog\Application\Command\AddVariant\AddVariantHandler;
use Modules\Catalog\Application\Command\AddWarranty\AddWarranty;
use Modules\Catalog\Application\Command\AddWarranty\AddWarrantyHandler;
use Modules\Catalog\Application\Command\ArchiveImportedProducts\ArchiveImportedProducts;
use Modules\Catalog\Application\Command\ArchiveImportedProducts\ArchiveImportedProductsHandler;
use Modules\Catalog\Application\Command\ArchiveVariant\ArchiveVariant;
use Modules\Catalog\Application\Command\ArchiveVariant\ArchiveVariantHandler;
use Modules\Catalog\Application\Command\BringInImport\BringInImport;
use Modules\Catalog\Application\Command\BringInImport\BringInImportHandler;
use Modules\Catalog\Application\Command\BringInImportProducts\BringInImportProducts;
use Modules\Catalog\Application\Command\BringInImportProducts\BringInImportProductsHandler;
use Modules\Catalog\Application\Command\CorrectStoreFillCode\CorrectStoreFillCode;
use Modules\Catalog\Application\Command\CorrectStoreFillCode\CorrectStoreFillCodeHandler;
use Modules\Catalog\Application\Command\CorrectVariantCode\CorrectVariantCode;
use Modules\Catalog\Application\Command\CorrectVariantCode\CorrectVariantCodeHandler;
use Modules\Catalog\Application\Command\DecideImportCodes\DecideImportCodes;
use Modules\Catalog\Application\Command\DecideImportCodes\DecideImportCodesHandler;
use Modules\Catalog\Application\Command\DecideImportNames\DecideImportNames;
use Modules\Catalog\Application\Command\DecideImportNames\DecideImportNamesHandler;
use Modules\Catalog\Application\Command\DeleteDraftProduct\DeleteDraftProduct;
use Modules\Catalog\Application\Command\DeleteDraftProduct\DeleteDraftProductHandler;
use Modules\Catalog\Application\Command\EditProductDetails\EditProductDetails;
use Modules\Catalog\Application\Command\EditProductDetails\EditProductDetailsHandler;
use Modules\Catalog\Application\Command\RemoveStoreFillItems\RemoveStoreFillItems;
use Modules\Catalog\Application\Command\RemoveStoreFillItems\RemoveStoreFillItemsHandler;
use Modules\Catalog\Application\Command\SetFilterValues\SetFilterValues;
use Modules\Catalog\Application\Command\SetFilterValues\SetFilterValuesHandler;
use Modules\Catalog\Application\Command\SetImportedBrand\SetImportedBrand;
use Modules\Catalog\Application\Command\SetImportedBrand\SetImportedBrandHandler;
use Modules\Catalog\Application\Command\SetImportedCategory\SetImportedCategory;
use Modules\Catalog\Application\Command\SetImportedCategory\SetImportedCategoryHandler;
use Modules\Catalog\Application\Command\SetImportedFilters\SetImportedFilters;
use Modules\Catalog\Application\Command\SetImportedFilters\SetImportedFiltersHandler;
use Modules\Catalog\Application\Command\SetImportedSearchWords\SetImportedSearchWords;
use Modules\Catalog\Application\Command\SetImportedSearchWords\SetImportedSearchWordsHandler;
use Modules\Catalog\Application\Command\SetImportedSlugs\SetImportedSlugs;
use Modules\Catalog\Application\Command\SetImportedSlugs\SetImportedSlugsHandler;
use Modules\Catalog\Application\Command\SetImportedWarranty\SetImportedWarranty;
use Modules\Catalog\Application\Command\SetImportedWarranty\SetImportedWarrantyHandler;
use Modules\Catalog\Application\Command\SetProductGallery\SetProductGallery;
use Modules\Catalog\Application\Command\SetProductGallery\SetProductGalleryHandler;
use Modules\Catalog\Application\Command\SetRelations\SetRelations;
use Modules\Catalog\Application\Command\SetRelations\SetRelationsHandler;
use Modules\Catalog\Application\Command\SwitchOnStoreFillItems\SwitchOnStoreFillItems;
use Modules\Catalog\Application\Command\SwitchOnStoreFillItems\SwitchOnStoreFillItemsHandler;
use Modules\Catalog\Application\Command\UploadStoreFill\UploadStoreFill;
use Modules\Catalog\Application\Command\UploadStoreFill\UploadStoreFillHandler;
use Modules\Catalog\Application\Import\ImportSummary;
use Modules\Catalog\Application\Query\ListStoreFills\ListStoreFills;
use Modules\Catalog\Application\Query\ListStoreFills\ListStoreFillsHandler;
use Modules\Catalog\Application\Query\ViewImport\ViewImport;
use Modules\Catalog\Application\Query\ViewImport\ViewImportHandler;
use Modules\Catalog\Application\Query\ViewStoreFill\StoreFillItemView;
use Modules\Catalog\Application\Query\ViewStoreFill\ViewStoreFill;
use Modules\Catalog\Application\Query\ViewStoreFill\ViewStoreFillHandler;
use Modules\Catalog\Domain\Exception\ImportRefused;
use Modules\Catalog\Domain\Exception\ImportUndecided;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\ListItemNotFound;
use Modules\Catalog\Domain\Exception\NameTaken;
use Modules\Catalog\Infrastructure\Import\DiskImportArchives;
use Modules\Catalog\Infrastructure\Queue\BringInImportJob;
use Modules\Catalog\Public\Events\StoreListingChanged;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogFixtures as Cx;
use Tests\Modules\Catalog\Support\CatalogImports as Ix;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;

use function Pest\Laravel\seed;

/*
| Step 6 after its reviews (catalog.md amendment 8): names several catalog items answer to and web
| addresses that would collide, decided on the page; the confirm asking everything again; bringing in
| holding back, replacing whole, updating and restoring; the page's picks brought in; a job the queue
| gave up on; the zip checked as it is unpacked; accepting linking back; the store file kept to its
| store, its items standing as switching on would find them.
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

function catalogReviewEnglish(string $table, string $id): string
{
    return (string) DB::table("catalog.{$table}")->where('id', $id)->value('name_en');
}

function catalogReviewState(string $importId, int $number): string
{
    return (string) DB::table('catalog.import_products')->where('import_id', $importId)->where('number', $number)->value('state');
}

/**
 * @param  list<array<string, mixed>>  $items
 */
function catalogReviewFill(array $items, string $store = 'sa'): string
{
    return app(UploadStoreFillHandler::class)->handle(new UploadStoreFill(Fx::storeId($store), Ix::temp(json_encode(['format' => 'touchwood-store-fill/1', 'items' => $items], JSON_THROW_ON_ERROR)), 'prices.json'));
}

function catalogReviewFillItem(string $importId, int $number): string
{
    return (string) DB::table('catalog.store_fill_items')->where('import_id', $importId)->where('number', $number)->value('id');
}

describe('a name several catalog items answer to (8(d))', function () {
    it('is asked on the page with how many it matches, and the one picked is brought in', function () {
        $terms = ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Terms']]]]];
        [$first, $second] = Fx::asSystem(fn (): array => [
            app(AddWarrantyHandler::class)->handle(new AddWarranty('ضمان سنتين', 'Two years', $terms, $terms, 24)),
            app(AddWarrantyHandler::class)->handle(new AddWarranty('ضمان سنتين', 'Two years', $terms, $terms, 24)),
        ]);
        $import = Ix::uploadProducts([Ix::product('1', ['warranty' => 'two years'])]);
        $name = Ix::nameId($import, 'WARRANTY', 'two years');

        expect(DB::table('catalog.import_names')->where('id', $name)->value('matches'))->toBe(2)
            ->and(app(ViewImportHandler::class)->handle(new ViewImport($import))->names[0]->matches)->toBe(2);

        Ix::decideNames($import, ['two years' => ['decision' => 'EXISTING', 'target_id' => $second]]);
        Ix::bringIn($import);

        expect(DB::table('catalog.products')->where('id', Ix::broughtIn($import, 1))->value('warranty_id'))->toBe($second)
            ->and($second)->not->toBe($first);
    });
});

describe('web addresses that would collide (8(c))', function () {
    it('wait for an address of their own, given on the page, before bringing in', function () {
        $catalog = Px::product('Handle');
        $taken = (array) (DB::table('catalog.products')->where('id', $catalog)->first(['name_ar', 'name_en']) ?? throw new LogicException('No product.'));
        $import = Ix::uploadProducts([
            Ix::product('1', ['name' => ['ar' => 'درج', 'en' => 'Drawer']]),
            Ix::product('2', ['name' => ['ar' => 'درج', 'en' => 'Drawer']]),
            Ix::product('3', ['name' => ['ar' => (string) $taken['name_ar'], 'en' => (string) $taken['name_en']]]),
            Ix::product('4'),
        ]);

        expect(array_map(static fn ($product): array => $product->addressTaken, app(ViewImportHandler::class)->handle(new ViewImport($import))->products))
            ->toBe([['ar', 'en'], ['ar', 'en'], ['ar', 'en'], []]);

        try {
            app(BringInImportHandler::class)->handle(new BringInImport($import));
        } catch (ImportUndecided $undecided) {
        }

        expect([$undecided->names ?? null, $undecided->codes ?? null, $undecided->addresses ?? null])->toBe([0, 0, 3]);

        app(SetImportedSlugsHandler::class)->handle(new SetImportedSlugs($import, Ix::productId($import, 1), 'درج-معدني', 'metal-drawer'));
        app(SetImportedSlugsHandler::class)->handle(new SetImportedSlugs($import, Ix::productId($import, 3), 'مقبض-جديد', 'new-handle'));
        expect(fn () => app(SetImportedSlugsHandler::class)->handle(new SetImportedSlugs($import, Ix::productId($import, 4), 'Not A Slug', null)))->toThrow(InvalidCatalogAttribute::class, 'slug_ar');

        Ix::bringIn($import);

        expect(DB::table('catalog.imports')->where('id', $import)->value('state'))->toBe('IN')
            ->and(DB::table('catalog.product_slugs')->where('product_id', Ix::broughtIn($import, 1))->where('is_current', true)->orderBy('locale')->pluck('slug')->all())->toBe(['درج-معدني', 'metal-drawer']);
    });

    it('give a new category its own address when the one made from its names is taken', function () {
        $import = Ix::uploadProducts([Ix::product('1', ['category' => 'Kitchens / Accessories']), Ix::product('2', ['category' => 'Wardrobes / Accessories'])]);
        $decide = fn (string $written, array $decision) => app(DecideImportNamesHandler::class)->handle(new DecideImportNames($import, [['name_id' => Ix::nameId($import, 'CATEGORY', $written), ...$decision]]));
        $decide('Kitchens', Ix::create('مطابخ', 'Kitchens'));
        $decide('Wardrobes', Ix::create('خزائن', 'Wardrobes'));
        $decide('Kitchens / Accessories', Ix::create('إكسسوارات', 'Accessories'));

        expect(fn () => $decide('Wardrobes / Accessories', Ix::create('إكسسوارات', 'Accessories')))->toThrow(InvalidCatalogAttribute::class, 'an address no other category has, now or before: give one')
            ->and(fn () => $decide('Wardrobes', ['decision' => 'CREATE', 'name_ar' => 'خزائن', 'name_en' => 'Wardrobes', 'slug_en' => 'Not A Slug']))->toThrow(InvalidCatalogAttribute::class, 'lower-case Latin letters and digits');

        $decide('Wardrobes / Accessories', [...Ix::create('إكسسوارات', 'Accessories'), 'slug_ar' => 'إكسسوارات-خزائن', 'slug_en' => 'wardrobe-accessories']);
        Ix::bringIn($import);

        expect(DB::table('catalog.imports')->where('id', $import)->value('state'))->toBe('IN')
            ->and(DB::table('catalog.category_slugs')->where('slug', 'wardrobe-accessories')->exists())->toBeTrue();
    });
});

describe('the confirm asks again', function () {
    it('lets a decision go when the catalog product it was about is gone, and audits what it asked again', function () {
        $draft = Px::product();
        Px::variant($draft, '4400');
        $import = Ix::uploadProducts([Ix::product('4400')]);
        app(DecideImportCodesHandler::class)->handle(new DecideImportCodes($import, [['product_id' => Ix::productId($import, 1), 'decision' => 'UPDATE']]));
        Fx::asSystem(fn () => app(DeleteDraftProductHandler::class)->handle(new DeleteDraftProduct($draft)));

        Ix::bringIn($import);

        expect(DB::table('catalog.import_products')->where('import_id', $import)->first(['decision', 'state']))->toEqual((object) ['decision' => null, 'state' => 'IN'])
            ->and(Fx::audits('catalog.import.checked_again', $import))->toBe(1);
    });

    it('sends back to wait a product whose new codes another product took meanwhile', function () {
        $ready = Px::ready();
        $code = (string) DB::table('catalog.variants')->where('id', $ready['variants'][0])->value('code');
        $import = Ix::uploadProducts([Ix::product($code)]);
        app(DecideImportCodesHandler::class)->handle(new DecideImportCodes($import, [['product_id' => Ix::productId($import, 1), 'decision' => 'RECODE', 'new_codes' => [$code => '9300']]]));
        Px::variant(Px::product(), '9300');

        expect(fn () => app(BringInImportHandler::class)->handle(new BringInImport($import)))->toThrow(ImportUndecided::class);
        expect(DB::table('catalog.import_products')->where('import_id', $import)->value('decision'))->toBeNull();
    });
});

describe('bringing in', function () {
    it('holds back a product a value of whose variants was refused, its attribute the catalog\'s', function () {
        $ready = Px::ready(['60 cm']);
        $width = catalogReviewEnglish('attributes', $ready['width']);
        $import = Ix::uploadProducts([Ix::product('7100', ['variants' => [['code' => '7100', 'values' => [$width => '61 cm']]]]), Ix::product('7200')]);
        Ix::decideNames($import);

        Ix::bringIn($import);

        expect([catalogReviewState($import, 1), catalogReviewState($import, 2)])->toBe(['HELD', 'IN']);
    });

    it('replaces a draft whole: its variants the file does not name archived, its relations, gallery and words cleared', function () {
        $width = Px::attribute('Width');
        [$sixtyValue, $eightyValue] = [Px::value($width, '60 cm'), Px::value($width, '80 cm')];
        $draft = Px::product();
        Px::variantAttributes($draft, [$width]);
        $related = Px::ready();
        Fx::asSystem(function () use ($draft, $related): void {
            app(SetProductGalleryHandler::class)->handle(new SetProductGallery($draft, [Cx::media()]));
            app(SetRelationsHandler::class)->handle(new SetRelations($draft, 'RELATED', [$related['product']]));
        });
        $sixty = Px::variant($draft, '4400', [$width => $sixtyValue]);
        $eighty = Px::variant($draft, '4401', [$width => $eightyValue]);
        DB::table('catalog.product_search_words')->insert(['product_id' => $draft, 'normalized' => 'old', 'word' => 'old', 'position' => 0]);
        $import = Ix::uploadProducts([Ix::product('4400', ['variants' => [['code' => '4400', 'values' => [catalogReviewEnglish('attributes', $width) => '60 cm']]]])]);
        app(DecideImportCodesHandler::class)->handle(new DecideImportCodes($import, [['product_id' => Ix::productId($import, 1), 'decision' => 'REPLACE']]));

        Ix::bringIn($import);

        expect(catalogReviewState($import, 1))->toBe('REPLACED')
            ->and(DB::table('catalog.variants')->whereIn('id', [$sixty, $eighty])->orderBy('position')->pluck('is_archived', 'id')->all())->toEqual([$sixty => false, $eighty => true])
            ->and(DB::table('catalog.product_relations')->where('product_id', $draft)->count())->toBe(0)
            ->and(DB::table('catalog.product_photos')->where('product_id', $draft)->count())->toBe(0)
            ->and(DB::table('catalog.product_search_words')->where('product_id', $draft)->count())->toBe(0);
    });

    it('corrects a draft\'s code when the file gives its variant\'s values under a code it does not carry (P33, amendment 11(b))', function () {
        $width = Px::attribute('Width');
        [$sixtyValue, $eightyValue] = [Px::value($width, '60 cm'), Px::value($width, '80 cm')];
        $draft = Px::product();
        Px::variantAttributes($draft, [$width]);
        $sixty = Px::variant($draft, '4500', [$width => $sixtyValue]);
        $eighty = Px::variant($draft, '4501', [$width => $eightyValue]);
        $widthEn = catalogReviewEnglish('attributes', $width);
        $import = Ix::uploadProducts([Ix::product('4500', ['variants' => [
            ['code' => '4500', 'values' => [$widthEn => '60 cm']],
            ['code' => '4502', 'values' => [$widthEn => '80 cm'], 'weight_g' => 80],
        ]])]);
        app(DecideImportCodesHandler::class)->handle(new DecideImportCodes($import, [['product_id' => Ix::productId($import, 1), 'decision' => 'UPDATE']]));

        Ix::bringIn($import);

        // The 80 cm variant is the same one, its code corrected; the code it gave up is free again.
        expect(catalogReviewState($import, 1))->toBe('UPDATED')
            ->and(DB::table('catalog.variants')->where('product_id', $draft)->orderBy('position')->pluck('code', 'id')->all())->toBe([$sixty => '4500', $eighty => '4502'])
            ->and(DB::table('catalog.variants')->where('id', $eighty)->value('weight_grams'))->toBe(80)
            ->and(DB::table('catalog.product_codes')->where('code', '4501')->exists())->toBeFalse();
    });

    it('updates a product, keeping what the file does not give, and brings back a variant it names', function () {
        $ready = Px::ready(['60 cm', '80 cm']);
        [$sixty, $eighty] = $ready['variants'];
        $warranty = Px::warranty();
        $product = DB::table('catalog.products')->where('id', $ready['product'])->first() ?? throw new LogicException('No product.');
        Fx::asSystem(function () use ($ready, $product, $warranty, $eighty): void {
            app(EditProductDetailsHandler::class)->handle(new EditProductDetails($ready['product'], (string) $product->name_ar, (string) $product->name_en, (string) $product->brand_id, descriptionAr: json_decode((string) $product->description_ar, true), descriptionEn: json_decode((string) $product->description_en, true), categoryId: (string) $product->category_id, warrantyId: $warranty));
            app(ArchiveVariantHandler::class)->handle(new ArchiveVariant($eighty));
        });
        DB::table('catalog.variants')->where('id', $sixty)->update(['length_mm' => 600]);
        $codes = DB::table('catalog.variants')->whereIn('id', [$sixty, $eighty])->pluck('code', 'id');
        $width = catalogReviewEnglish('attributes', $ready['width']);
        $import = Ix::uploadProducts([Ix::product((string) $codes[$sixty], [
            'variants' => [['code' => (string) $codes[$sixty], 'values' => [$width => '60 cm'], 'weight_g' => 900], ['code' => (string) $codes[$eighty], 'values' => [$width => '80 cm']]],
        ])]);
        app(DecideImportCodesHandler::class)->handle(new DecideImportCodes($import, [['product_id' => Ix::productId($import, 1), 'decision' => 'UPDATE']]));

        Ix::bringIn($import);

        expect(catalogReviewState($import, 1))->toBe('UPDATED')
            ->and((array) DB::table('catalog.products')->where('id', $ready['product'])->first(['brand_id', 'warranty_id', 'stage']))->toBe(['brand_id' => $product->brand_id, 'warranty_id' => $warranty, 'stage' => 'READY'])
            ->and((array) DB::table('catalog.variants')->where('id', $sixty)->first(['weight_grams', 'length_mm']))->toBe(['weight_grams' => 900, 'length_mm' => 600])
            ->and(DB::table('catalog.variants')->where('id', $eighty)->value('is_archived'))->toBeFalse();
    });

    it('brings in the category, warranty and filter values picked on the page', function () {
        $category = Px::category('Hinges');
        $warranty = Px::warranty();
        $use = Px::attribute('Use', 'FILTERABLE');
        $kitchen = Px::value($use, 'Kitchen');
        $import = Ix::uploadProducts([Ix::product('1')]);
        app(SetImportedCategoryHandler::class)->handle(new SetImportedCategory($import, null, $category, 'REPLACE'));
        app(SetImportedWarrantyHandler::class)->handle(new SetImportedWarranty($import, null, $warranty, 'REPLACE'));
        app(SetImportedFiltersHandler::class)->handle(new SetImportedFilters($import, null, [$kitchen], 'ADD'));

        Ix::bringIn($import);

        $product = Ix::broughtIn($import, 1);
        expect((array) DB::table('catalog.products')->where('id', $product)->first(['category_id', 'warranty_id']))->toBe(['category_id' => $category, 'warranty_id' => $warranty])
            ->and(DB::table('catalog.product_filter_values')->where('product_id', $product)->pluck('value_id')->all())->toBe([$kitchen]);
    });

    it('counts what the catalog\'s product it updates has when brought in, whatever was decided when the page changed it', function () {
        $use = Px::attribute('Use', 'FILTERABLE');
        [$kitchen, $bath] = [Px::value($use, 'Kitchen'), Px::value($use, 'Bath')];
        [$warranty, $own] = [Px::warranty(), Px::warranty()];
        // The first has no warranty; the second has one, and words and filter values of its own.
        [$bare, $full] = [Px::ready(), Px::ready()];
        DB::table('catalog.products')->where('id', $full['product'])->update(['warranty_id' => $own]);
        DB::table('catalog.product_search_words')->insert([
            ['product_id' => $bare['product'], 'normalized' => 'runner', 'word' => 'runner', 'position' => 0],
            ['product_id' => $full['product'], 'normalized' => 'rail', 'word' => 'rail', 'position' => 0],
        ]);
        Fx::asSystem(function () use ($bare, $full, $kitchen): void {
            app(SetFilterValuesHandler::class)->handle(new SetFilterValues($bare['product'], [$kitchen]));
            app(SetFilterValuesHandler::class)->handle(new SetFilterValues($full['product'], [$kitchen]));
        });
        $before = static fn (array $ready): array => (array) DB::table('catalog.products')->where('id', $ready['product'])->first(['brand_id', 'category_id']);
        [$bareBefore, $fullBefore] = [$before($bare), $before($full)];
        $file = static function (array $ready): array {
            $code = (string) DB::table('catalog.variants')->where('id', $ready['variants'][0])->value('code');

            return Ix::product($code, ['variants' => [['code' => $code, 'values' => [catalogReviewEnglish('attributes', $ready['width']) => '60 cm']]]]);
        };
        $import = Ix::uploadProducts([$file($bare), $file($full)]);
        [$first, $second] = [Ix::productId($import, 1), Ix::productId($import, 2)];

        // Changed while their codes still wait for a decision: what each has is not known yet.
        expect(app(SetImportedSearchWordsHandler::class)->handle(new SetImportedSearchWords($import, null, ['slide'], 'FILL_EMPTY')))->toBe(2);
        app(SetImportedFiltersHandler::class)->handle(new SetImportedFilters($import, [$first], [$bath], 'ADD'));
        app(SetImportedFiltersHandler::class)->handle(new SetImportedFilters($import, [$second], [$bath], 'FILL_EMPTY'));
        app(SetImportedCategoryHandler::class)->handle(new SetImportedCategory($import, null, Px::category('Other'), 'FILL_EMPTY'));
        app(SetImportedWarrantyHandler::class)->handle(new SetImportedWarranty($import, null, $warranty, 'FILL_EMPTY'));
        app(SetImportedBrandHandler::class)->handle(new SetImportedBrand($import, null, Px::brand('Other'), 'FILL_EMPTY'));
        app(DecideImportCodesHandler::class)->handle(new DecideImportCodes($import, [['product_id' => $first, 'decision' => 'UPDATE'], ['product_id' => $second, 'decision' => 'UPDATE']]));
        // The first's words go meanwhile: the words asked for the empty are given to it after all.
        DB::table('catalog.product_search_words')->where('product_id', $bare['product'])->delete();

        Ix::bringIn($import);

        $has = static fn (array $ready): array => [
            (array) DB::table('catalog.products')->where('id', $ready['product'])->first(['brand_id', 'category_id', 'warranty_id']),
            DB::table('catalog.product_search_words')->where('product_id', $ready['product'])->pluck('word')->all(),
            DB::table('catalog.product_filter_values')->where('product_id', $ready['product'])->pluck('value_id')->sort()->values()->all(),
        ];
        $values = [$kitchen, $bath];
        sort($values);

        expect([catalogReviewState($import, 1), catalogReviewState($import, 2)])->toBe(['UPDATED', 'UPDATED'])
            ->and($has($bare))->toBe([[...$bareBefore, 'warranty_id' => $warranty], ['slide'], $values])
            ->and($has($full))->toBe([[...$fullBefore, 'warranty_id' => $own], ['rail'], [$kitchen]]);
    });

    it('leaves the import failed, never bringing in, when the queue gives up on the work', function () {
        $import = Ix::uploadProducts([Ix::product('1')]);
        app(BringInImportHandler::class)->handle(new BringInImport($import));

        (new BringInImportJob($import))->failed(new RuntimeException('killed'));

        expect((array) DB::table('catalog.imports')->where('id', $import)->first(['state', 'failure']))->toBe(['state' => 'FAILED', 'failure' => 'bringing in: the work stopped before it ended; the failed jobs screen has its details']);
    });

    it('leaves the import failed when the work itself is refused', function () {
        $import = Ix::uploadProducts([Ix::product('1')]);
        app(BringInImportHandler::class)->handle(new BringInImport($import));
        Fx::actAsAdmin(['*'], CatalogPermissions::jobs());

        app(BringInImportProductsHandler::class)->handle(new BringInImportProducts($import));

        expect(DB::table('catalog.imports')->where('id', $import)->value('state'))->toBe('FAILED');
    });
});

describe('the zip, unpacked', function () {
    it('refuses two entries that are one path, and a products.json named twice', function () {
        $problems = static function (array $files): array {
            try {
                Ix::upload(Ix::zip($files));
            } catch (ImportRefused $refused) {
                return $refused->problems;
            }

            throw new LogicException('Not refused.');
        };

        expect($problems(['products.json' => Ix::json([Ix::product('1')]), 'photos/a.jpg' => 'a', 'photos\\a.jpg' => 'b']))->toBe([['at' => 'file', 'problem' => 'photos/a.jpg only once in the zip']])
            ->and($problems(['products.json' => Ix::json([Ix::product('1')]), './products.json' => Ix::json([Ix::product('2')])]))->toBe([['at' => 'file', 'problem' => 'products.json only once in the zip']]);
    });

    it('refuses a photo that does not come out the size the zip gives, leaving no file behind', function () {
        $zip = Ix::zip(['products.json' => '{}', 'a.jpg' => str_repeat('x', 100)]);
        // The zip's own headers made to claim 60 bytes unpacked where 100 come out: the size sits 22
        // bytes into the entry's local header, 24 into its central one, the name 30 and 46 bytes in.
        $bytes = (string) file_get_contents($zip);
        $local = (int) strpos($bytes, 'a.jpg') - 30;
        $central = (int) strpos($bytes, 'a.jpg', (int) strpos($bytes, "PK\x01\x02")) - 46;
        $bytes = substr_replace($bytes, pack('V', 60), $local + 22, 4);
        $bytes = substr_replace($bytes, pack('V', 60), $central + 24, 4);
        file_put_contents($zip, $bytes);
        $directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'tw-zip-'.bin2hex(random_bytes(4));
        mkdir($directory);
        Ix::remember($directory);
        $archives = new DiskImportArchives(app(Factory::class), 'local', $directory);
        $kept = $archives->keep('01jaaaaaaaaaaaaaaaaaaaaaac', $zip);

        expect(fn () => $archives->unpack($kept, ['a.jpg']))->toThrow(InvalidCatalogAttribute::class, 'a.jpg: not the size the zip gives for it')
            ->and(glob($directory.DIRECTORY_SEPARATOR.'*') ?: [])->toBe([]);
    });
});

describe('accepting', function () {
    it('links a product accepted earlier to the one it names, once that one is accepted too', function () {
        $category = catalogReviewEnglish('categories', Px::category('Hinges'));
        $whole = ['description' => ['ar' => 'وصف', 'en' => 'About it'], 'category' => $category];
        $files = ['products.json' => Ix::json([
            Ix::product('6100', [...$whole, 'photos' => ['6100.jpg'], 'related' => ['6200']]),
            Ix::product('6200', [...$whole, 'photos' => ['6200.jpg']]),
        ]), '6100.jpg' => Ix::image('6100.jpg', 21), '6200.jpg' => Ix::image('6200.jpg', 22)];
        $import = Ix::upload(Ix::zip($files));
        Ix::bringIn($import);
        DB::table('platform.media')->update(['variants_status' => 'READY', 'variants_generated_at' => now()]);
        $accept = fn (int $number) => app(AcceptImportedProductsHandler::class)->handle(new AcceptImportedProducts($import, [Ix::productId($import, $number)]));

        $accept(1);
        expect(DB::table('catalog.product_relations')->where('product_id', Ix::broughtIn($import, 1))->count())->toBe(0);

        $accept(2);
        expect(DB::table('catalog.product_relations')->where('product_id', Ix::broughtIn($import, 1))->pluck('related_id')->all())->toBe([Ix::broughtIn($import, 2)])
            ->and(DB::table('catalog.store_variants')->where('product_id', Ix::broughtIn($import, 1))->where('is_active', true)->exists())->toBeFalse();
    });

    it('links one accepted earlier by any code the one it names holds — a code corrected since included', function () {
        $category = catalogReviewEnglish('categories', Px::category('Hinges'));
        $whole = ['description' => ['ar' => 'وصف', 'en' => 'About it'], 'category' => $category];
        $files = ['products.json' => Ix::json([
            Ix::product('6100', [...$whole, 'photos' => ['6100.jpg'], 'related' => ['6299']]),
            Ix::product('6200', [...$whole, 'photos' => ['6200.jpg']]),
        ]), '6100.jpg' => Ix::image('6100.jpg', 23), '6200.jpg' => Ix::image('6200.jpg', 24)];
        $import = Ix::upload(Ix::zip($files));
        Ix::bringIn($import);
        DB::table('platform.media')->update(['variants_status' => 'READY', 'variants_generated_at' => now()]);
        $named = (string) DB::table('catalog.variants')->where('product_id', Ix::broughtIn($import, 2))->value('id');
        Fx::asSystem(fn () => app(CorrectVariantCodeHandler::class)->handle(new CorrectVariantCode($named, '6299')));
        $accept = fn (int $number) => app(AcceptImportedProductsHandler::class)->handle(new AcceptImportedProducts($import, [Ix::productId($import, $number)]));

        $accept(1);
        $accept(2);

        expect(DB::table('catalog.product_relations')->where('product_id', Ix::broughtIn($import, 1))->pluck('related_id')->all())->toBe([Ix::broughtIn($import, 2)]);
    });

    it('archives every one the import created, passing the others by', function () {
        $import = Ix::uploadProducts([Ix::product('1'), Ix::product('2')]);
        Ix::bringIn($import);
        DB::table('catalog.import_products')->where('import_id', $import)->where('number', 1)->update(['state' => 'ACCEPTED']);

        expect(app(ArchiveImportedProductsHandler::class)->handle(new ArchiveImportedProducts($import, null)))->toBe(1)
            ->and([catalogReviewState($import, 1), catalogReviewState($import, 2)])->toBe(['ACCEPTED', 'ARCHIVED']);
    });
});

describe('the store file', function () {
    it("is reached only by those who fill its own store: another store's answers as a file that does not exist", function () {
        $file = catalogReviewFill([['code' => '1', 'price' => 1]]);
        $item = catalogReviewFillItem($file, 1);
        $calls = static fn (string $id): array => [
            fn () => app(ViewStoreFillHandler::class)->handle(new ViewStoreFill($id)),
            fn () => app(CorrectStoreFillCodeHandler::class)->handle(new CorrectStoreFillCode($id, $item, '2')),
            fn () => app(RemoveStoreFillItemsHandler::class)->handle(new RemoveStoreFillItems($id, [$item])),
            fn () => app(SwitchOnStoreFillItemsHandler::class)->handle(new SwitchOnStoreFillItems($id, null)),
        ];

        // The job in another store: this store's file and an id no file has answer alike (§7).
        Fx::actAsAdmin(['eg'], [CatalogPermissions::LISTING_FILL]);
        foreach ([...$calls($file), ...$calls('01arz3ndektsv4rrffq69g5fav')] as $call) {
            expect($call)->toThrow(ListItemNotFound::class);
        }

        // The job nowhere: not allowed, whatever the id.
        Fx::actAsAdmin(['sa'], [CatalogPermissions::LISTING_CHOOSE]);
        foreach ($calls($file) as $call) {
            expect($call)->toThrow(Unauthorized::class);
        }
    });

    it('is no products file, nor a products file one', function () {
        $products = Ix::uploadProducts([Ix::product('1', ['brand' => 'Blumm'])]);
        $file = catalogReviewFill([['code' => '1', 'price' => 1]]);

        expect(fn () => app(SwitchOnStoreFillItemsHandler::class)->handle(new SwitchOnStoreFillItems($products, null)))->toThrow(ListItemNotFound::class)
            ->and(fn () => app(ViewStoreFillHandler::class)->handle(new ViewStoreFill($products)))->toThrow(ListItemNotFound::class)
            ->and(fn () => app(DecideImportNamesHandler::class)->handle(new DecideImportNames($file, [['name_id' => Ix::nameId($products, 'BRAND', 'Blumm'), 'decision' => 'REFUSE']])))->toThrow(ListItemNotFound::class)
            ->and(fn () => app(ViewImportHandler::class)->handle(new ViewImport($file)))->toThrow(ListItemNotFound::class);
    });

    it('lists every problem of a file not in its format', function () {
        try {
            catalogReviewFill([['code' => '1304', 'price' => 1], ['code' => '1304', 'price' => -2]]);
        } catch (ImportRefused $refused) {
        }

        expect($refused->problems ?? null)->toBe([
            ['at' => 'item 2 › code', 'problem' => '1304 again: item 1 names it already'],
            ['at' => 'item 2 › price', 'problem' => 'a number of at least 0, with at most 6 decimal places'],
        ]);
    });

    it('switches on the one variant carrying the code, and tells the modules above', function () {
        Event::fake([StoreListingChanged::class]);
        $ready = Px::ready(['60 cm']);
        $code = (string) DB::table('catalog.variants')->where('id', $ready['variants'][0])->value('code');
        Fx::asSystem(fn (): string => app(AddVariantHandler::class)->handle(new AddVariant($ready['product'], '7701', [$ready['width'] => Px::value($ready['width'], '80 cm')])));
        $file = catalogReviewFill([['code' => $code, 'price' => 10]]);

        app(SwitchOnStoreFillItemsHandler::class)->handle(new SwitchOnStoreFillItems($file, null));

        $on = DB::table('catalog.store_variants')->where('store_id', Fx::storeId('sa'))->where('is_active', true)->pluck('variant_id')->all();
        expect($on)->toBe([$ready['variants'][0]])
            ->and(DB::table('catalog.listing')->where('store_id', Fx::storeId('sa'))->where('product_id', $ready['product'])->exists())->toBeTrue();
        Event::assertDispatched(StoreListingChanged::class, fn (StoreListingChanged $event): bool => $event->storeId === Fx::storeId('sa') && $event->variantIds === [$ready['variants'][0]]);
    });

    it('shows an item as switching on would find it, and never who uploaded the file', function () {
        $archived = Px::ready(['60 cm', '80 cm']);
        $corrected = Px::ready();
        $code = fn (string $variant): string => (string) DB::table('catalog.variants')->where('id', $variant)->value('code');
        [$archivedCode, $oldCode] = [$code($archived['variants'][1]), $code($corrected['variants'][0])];
        Fx::asSystem(function () use ($archived, $corrected): void {
            app(ArchiveVariantHandler::class)->handle(new ArchiveVariant($archived['variants'][1]));
            app(CorrectVariantCodeHandler::class)->handle(new CorrectVariantCode($corrected['variants'][0], '8800'));
        });
        $file = catalogReviewFill([['code' => $archivedCode, 'price' => 1], ['code' => $oldCode, 'price' => 2]]);
        Fx::actAsAdmin(['sa'], [CatalogPermissions::LISTING_FILL]);

        expect(array_map(static fn (StoreFillItemView $item): ?string => $item->standing, app(ViewStoreFillHandler::class)->handle(new ViewStoreFill($file))->items))->toBe(['ARCHIVED', 'UNKNOWN'])
            ->and(array_map(static fn (ImportSummary $summary): ?string => $summary->uploadedBy, app(ListStoreFillsHandler::class)->handle(new ListStoreFills(Fx::storeId('sa')))->imports))->toBe([null]);
    });
});

describe('a new value', function () {
    it('is not made twice by one file under one attribute', function () {
        $width = catalogReviewEnglish('attributes', Px::attribute('Width'));
        $import = Ix::uploadProducts([
            Ix::product('1', ['variants' => [['code' => '1', 'values' => [$width => '60cm']]]]),
            Ix::product('2', ['variants' => [['code' => '2', 'values' => [$width => '60 cms']]]]),
        ]);
        $decide = fn (string $written) => app(DecideImportNamesHandler::class)->handle(new DecideImportNames($import, [['name_id' => Ix::nameId($import, 'VALUE', $written), ...Ix::create('60 سم', '60 cm')]]));
        $decide('60cm');

        expect(fn () => $decide('60 cms'))->toThrow(NameTaken::class);
    });
});
