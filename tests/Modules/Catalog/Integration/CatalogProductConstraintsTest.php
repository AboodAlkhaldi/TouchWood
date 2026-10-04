<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Catalog\Application\Command\AddLabel\AddLabel;
use Modules\Catalog\Application\Command\AddLabel\AddLabelHandler;
use Modules\Catalog\Application\Command\AddVariant\AddVariant;
use Modules\Catalog\Application\Command\AddVariant\AddVariantHandler;
use Modules\Catalog\Application\Command\EditProductDetails\EditProductDetails;
use Modules\Catalog\Application\Command\EditProductDetails\EditProductDetailsHandler;
use Modules\Catalog\Application\Command\SetProductGallery\SetProductGallery;
use Modules\Catalog\Application\Command\SetProductGallery\SetProductGalleryHandler;
use Modules\Catalog\Application\Command\SetRelations\SetRelations;
use Modules\Catalog\Application\Command\SetRelations\SetRelationsHandler;
use Modules\Catalog\Application\Command\SetVariantPhotos\SetVariantPhotos;
use Modules\Catalog\Application\Command\SetVariantPhotos\SetVariantPhotosHandler;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogFixtures as Cx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;

use function Pest\Laravel\seed;

/*
| The database's own refusals behind the products' code (handoff §5.3; review of step 3): each named
| CHECK, key or index of the products' migrations that no handler test reaches, refusing a row
| written past the code — each row breaking that one rule only (lesson 112). Two keys are left out:
| `variant_values_attribute` and `product_filter_values_attribute` cannot refuse alone, since the
| composite key to the attribute's own value (tested with the variants) refuses first.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

/**
 * A draft in a category, with a warranty and a set of widths; a variant of code 1001 with a detail; a
 * gallery photo, another photo on the variant; a ready product related to it, and another product.
 *
 * @return array<string, string>
 */
function catalogProductConstraintsRows(): array
{
    $width = Px::attribute('Width');
    $sixty = Px::value($width, '60 cm');
    $eighty = Px::value($width, '80 cm');
    $material = Px::attribute('Material', 'INFORMATIONAL');
    $finish = Px::attribute('Finish', 'INFORMATIONAL');
    $set = Px::set([$width]);
    $category = Px::category();
    $warranty = Px::warranty();
    $product = Px::product('Drawer');
    $related = Px::product('Hinge');
    $other = Px::product('Plate');
    DB::table('catalog.products')->where('id', $related)->update(['stage' => 'READY', 'category_id' => Px::category()]);
    [$photo, $variantPhoto] = [Cx::media(), Cx::media()];

    $variant = Fx::asSystem(function () use ($product, $category, $warranty, $set, $width, $sixty, $material, $photo, $variantPhoto, $related): string {
        $row = app(ProductRepository::class)->find($product) ?? throw new LogicException('No such product.');
        app(EditProductDetailsHandler::class)->handle(new EditProductDetails(
            $product, $row->name()->ar, $row->name()->en, $row->brandId(),
            categoryId: $category, warrantyId: $warranty, attributeSetId: $set,
        ));
        $variant = app(AddVariantHandler::class)->handle(new AddVariant($product, '1001', [$width => $sixty], [$material => ['text_ar' => 'خشب', 'text_en' => 'Wood']]));
        app(SetProductGalleryHandler::class)->handle(new SetProductGallery($product, [$photo]));
        app(SetVariantPhotosHandler::class)->handle(new SetVariantPhotos($variant, [$variantPhoto]));
        app(SetRelationsHandler::class)->handle(new SetRelations($product, 'RELATED', [$related]));

        return $variant;
    });

    $store = Fx::storeId('sa');
    $label = Fx::asSystem(fn (): string => app(AddLabelHandler::class)->handle(new AddLabel('عرض', 'Sale', 'green')));

    return compact('product', 'variant', 'width', 'eighty', 'material', 'finish', 'set', 'category', 'warranty', 'photo', 'variantPhoto', 'related', 'other', 'store', 'label');
}

/**
 * A store's row for a product, as its repository writes one, with these columns changed.
 *
 * @param  array<string, mixed>  $columns
 */
function catalogProductConstraintsStoreRow(string $store, string $product, array $columns = []): void
{
    DB::table('catalog.store_products')->insert([
        'store_id' => $store, 'product_id' => $product, 'not_available_now' => false, 'retail_minimum' => 1,
        'created_at' => CarbonImmutable::now(), 'updated_at' => CarbonImmutable::now(), ...$columns,
    ]);
}

/**
 * A store's row for a variant, with these columns changed.
 *
 * @param  array<string, mixed>  $columns
 */
function catalogProductConstraintsVariantRow(string $store, string $product, string $variant, array $columns = []): void
{
    DB::table('catalog.store_variants')->insert([
        'store_id' => $store, 'product_id' => $product, 'variant_id' => $variant, 'is_active' => true, 'not_available_now' => false,
        'sells_retail' => true, 'sells_wholesale' => false, 'created_at' => CarbonImmutable::now(), 'updated_at' => CarbonImmutable::now(), ...$columns,
    ]);
}

it('refuses what the code would never write', function (Closure $write, string $constraint) {
    $rows = catalogProductConstraintsRows();

    expect(fn () => DB::transaction(function () use ($write, $rows): bool {
        $write($rows);

        return true;
    }))->toThrow(QueryException::class, $constraint);
})->with([
    'a code another product holds' => [fn (array $r) => DB::table('catalog.product_codes')->insert(['code' => '1001', 'product_id' => $r['other'], 'created_at' => CarbonImmutable::now()]), 'product_codes_pkey'],
    'an address another product holds' => [fn (array $r) => DB::table('catalog.product_slugs')->insert([
        'locale' => 'en', 'slug' => DB::table('catalog.product_slugs')->where('product_id', $r['product'])->where('locale', 'en')->value('slug'),
        'product_id' => $r['other'], 'is_current' => false, 'created_at' => CarbonImmutable::now(),
    ]), 'product_slugs_pkey'],
    'an address in no language of ours' => [fn (array $r) => DB::table('catalog.product_slugs')->insert(['locale' => 'fr', 'slug' => 'درج', 'product_id' => $r['product'], 'is_current' => false, 'created_at' => CarbonImmutable::now()]), 'product_slugs_locale'],
    'an English address with a capital' => [fn (array $r) => DB::table('catalog.product_slugs')->where('product_id', $r['product'])->where('locale', 'en')->update(['slug' => 'Drawer']), 'product_slugs_shape'],
    'two current addresses in one language' => [fn (array $r) => DB::table('catalog.product_slugs')->insert(['locale' => 'en', 'slug' => 'drawer-again', 'product_id' => $r['product'], 'is_current' => true, 'created_at' => CarbonImmutable::now()]), 'product_slugs_one_current'],
    'a blank Arabic name' => [fn (array $r) => DB::table('catalog.products')->where('id', $r['product'])->update(['name_ar' => '   ']), 'products_name_ar_present'],
    'an English name on two lines' => [fn (array $r) => DB::table('catalog.products')->where('id', $r['product'])->update(['name_en' => "Drawer\nwide"]), 'products_name_en_present'],
    'a description that is not an object' => [fn (array $r) => DB::table('catalog.products')->where('id', $r['product'])->update(['description_ar' => '[]']), 'products_description_object'],
    'letters in a variant\'s code' => [fn (array $r) => DB::table('catalog.variants')->where('id', $r['variant'])->update(['code' => 'A1']), 'variants_code_format'],
    'a variant\'s place past the range' => [fn (array $r) => DB::table('catalog.variants')->where('id', $r['variant'])->update(['position' => 10001]), 'variants_position_range'],
    'a weight of nothing' => [fn (array $r) => DB::table('catalog.variants')->where('id', $r['variant'])->update(['weight_grams' => 0]), 'variants_weight_grams_range'],
    'a length of nothing' => [fn (array $r) => DB::table('catalog.variants')->where('id', $r['variant'])->update(['length_mm' => 0]), 'variants_length_mm_range'],
    'a width of nothing' => [fn (array $r) => DB::table('catalog.variants')->where('id', $r['variant'])->update(['width_mm' => 0]), 'variants_width_mm_range'],
    'a height of nothing' => [fn (array $r) => DB::table('catalog.variants')->where('id', $r['variant'])->update(['height_mm' => 0]), 'variants_height_mm_range'],
    'a combination that is not value ids' => [fn (array $r) => DB::table('catalog.variants')->where('id', $r['variant'])->update(['combination' => 'x']), 'variants_combination_shape'],
    'two values of one attribute' => [fn (array $r) => DB::table('catalog.variant_values')->insert(['variant_id' => $r['variant'], 'attribute_id' => $r['width'], 'value_id' => $r['eighty']]), 'variant_values_pkey'],
    'a blank detail' => [fn (array $r) => DB::table('catalog.variant_details')->insert(['variant_id' => $r['variant'], 'attribute_id' => $r['finish'], 'text_ar' => ' ', 'text_en' => 'Oak']), 'variant_details_text_present'],
    'a detail on two lines' => [fn (array $r) => DB::table('catalog.variant_details')->insert(['variant_id' => $r['variant'], 'attribute_id' => $r['finish'], 'text_ar' => 'بلوط', 'text_en' => "Oak\nwood"]), 'variant_details_text_present'],
    'a gallery place below the range' => [fn (array $r) => DB::table('catalog.product_photos')->where('product_id', $r['product'])->update(['position' => -1]), 'product_photos_position_range'],
    'a variant photo\'s place below the range' => [fn (array $r) => DB::table('catalog.variant_photos')->where('variant_id', $r['variant'])->update(['position' => -1]), 'variant_photos_position_range'],
    'a related product\'s place below the range' => [fn (array $r) => DB::table('catalog.product_relations')->where('product_id', $r['product'])->update(['position' => -1]), 'product_relations_position_range'],
    'deleting a category a product is in' => [fn (array $r) => DB::table('catalog.categories')->where('id', $r['category'])->delete(), 'products_category'],
    'deleting a warranty a product takes' => [fn (array $r) => DB::table('catalog.warranties')->where('id', $r['warranty'])->delete(), 'products_warranty'],
    'deleting a set a product takes' => [fn (array $r) => DB::table('catalog.attribute_sets')->where('id', $r['set'])->delete(), 'products_attribute_set'],
    'deleting a gallery photo\'s file' => [fn (array $r) => DB::table('platform.media')->where('id', $r['photo'])->delete(), 'product_photos_media'],
    'deleting a variant photo\'s file' => [fn (array $r) => DB::table('platform.media')->where('id', $r['variantPhoto'])->delete(), 'variant_photos_media'],
    'deleting a related product' => [fn (array $r) => DB::table('catalog.products')->where('id', $r['related'])->delete(), 'product_relations_related'],
    'deleting an attribute a detail is of' => [fn (array $r) => DB::table('catalog.attributes')->where('id', $r['material'])->delete(), 'variant_details_attribute'],
    'archived without the stage it left' => [fn (array $r) => DB::table('catalog.products')->where('id', $r['product'])->update(['stage' => 'ARCHIVED']), 'products_archived_from'],
    'archived from a stage that is no stage to leave' => [fn (array $r) => DB::table('catalog.products')->where('id', $r['product'])->update(['stage' => 'ARCHIVED', 'archived_from' => 'ARCHIVED']), 'products_archived_from'],
    'a stage left while not archived' => [fn (array $r) => DB::table('catalog.products')->where('id', $r['product'])->update(['archived_from' => 'DRAFT']), 'products_archived_from'],
    'a store that does not exist' => [fn (array $r) => catalogProductConstraintsStoreRow(strtolower((string) Str::ulid()), $r['product']), 'store_products_store'],
    'a retail minimum of nothing' => [fn (array $r) => catalogProductConstraintsStoreRow($r['store'], $r['product'], ['retail_minimum' => 0]), 'store_products_retail_minimum_range'],
    'a retail maximum past the range' => [fn (array $r) => catalogProductConstraintsStoreRow($r['store'], $r['product'], ['retail_maximum' => 100_001]), 'store_products_retail_maximum_range'],
    'a wholesale minimum of nothing' => [fn (array $r) => catalogProductConstraintsStoreRow($r['store'], $r['product'], ['wholesale_minimum' => 0]), 'store_products_wholesale_minimum_range'],
    'a label on a product the store has no row for' => [fn (array $r) => DB::table('catalog.store_product_labels')->insert(['store_id' => $r['store'], 'product_id' => $r['product'], 'label_id' => $r['label']]), 'store_product_labels_store_product'],
    'a wholesale maximum past the range' => [fn (array $r) => catalogProductConstraintsStoreRow($r['store'], $r['product'], ['wholesale_minimum' => 1, 'wholesale_maximum' => 100_001]), 'store_products_wholesale_maximum_range'],
    'a retail maximum below its minimum' => [fn (array $r) => catalogProductConstraintsStoreRow($r['store'], $r['product'], ['retail_minimum' => 5, 'retail_maximum' => 4]), 'store_products_retail_order'],
    'a wholesale maximum with no minimum' => [fn (array $r) => catalogProductConstraintsStoreRow($r['store'], $r['product'], ['wholesale_maximum' => 4]), 'store_products_wholesale_order'],
    'a variant row with no selling mode' => [function (array $r): void {
        catalogProductConstraintsStoreRow($r['store'], $r['product']);
        catalogProductConstraintsVariantRow($r['store'], $r['product'], $r['variant'], ['sells_retail' => false]);
    }, 'store_variants_one_mode'],
    'a variant row without its product\'s row' => [fn (array $r) => catalogProductConstraintsVariantRow($r['store'], $r['product'], $r['variant']), 'store_variants_store_product'],
    'a variant row under another product' => [function (array $r): void {
        catalogProductConstraintsStoreRow($r['store'], $r['other']);
        catalogProductConstraintsVariantRow($r['store'], $r['other'], $r['variant']);
    }, 'store_variants_variant'],
    'deleting a label a store shows' => [function (array $r): void {
        catalogProductConstraintsStoreRow($r['store'], $r['product']);
        DB::table('catalog.store_product_labels')->insert(['store_id' => $r['store'], 'product_id' => $r['product'], 'label_id' => $r['label']]);
        DB::table('catalog.labels')->where('id', $r['label'])->delete();
    }, 'store_product_labels_label'],
]);
