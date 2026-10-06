<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Catalog\Application\Command\UploadStoreFill\UploadStoreFill;
use Modules\Catalog\Application\Command\UploadStoreFill\UploadStoreFillHandler;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogImports as Ix;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;

use function Pest\Laravel\seed;

/*
| The database's own refusals behind the import's and the listing's code (handoff §5.3; review of
| step 7): each named CHECK of steps 5 and 6, refusing a row written past the code — each write
| breaking that one rule where the table allows it (lesson 112). An import of an unknown kind also
| breaks its state's rule; PostgreSQL tests a row's CHECKs in the order of their names, so the
| kind's is the one named.
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
 * A products file with a brand and a category to decide, a store's file, a ready product listed in a
 * store, and one search.
 *
 * @return array<string, string>
 */
function catalogImportConstraintsRows(): array
{
    $products = Ix::uploadProducts([Ix::product('1', ['brand' => 'Blumm', 'category' => 'Kitchens'])]);
    $fill = app(UploadStoreFillHandler::class)->handle(new UploadStoreFill(Fx::storeId('sa'), Ix::temp(json_encode(['format' => 'touchwood-store-fill/1', 'items' => [['code' => '1', 'price' => 1]]], JSON_THROW_ON_ERROR)), 'prices.json'));
    $ready = Px::ready();
    Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Fx::storeId('sa'), $ready['product'], true)));
    DB::table('catalog.search_log')->insert(['store_id' => Fx::storeId('sa'), 'locale' => 'en', 'query' => 'hinge', 'results' => 0]);

    return [
        'products' => $products,
        'fill' => $fill,
        'brand' => Ix::nameId($products, 'BRAND', 'Blumm'),
        'category' => Ix::nameId($products, 'CATEGORY', 'Kitchens'),
        'product' => Ix::productId($products, 1),
        'item' => (string) DB::table('catalog.store_fill_items')->where('import_id', $fill)->value('id'),
        'listed' => $ready['product'],
    ];
}

it('refuses what the code would never write', function (Closure $write, string $constraint) {
    $rows = catalogImportConstraintsRows();

    expect(fn () => DB::transaction(function () use ($write, $rows): bool {
        $write($rows);

        return true;
    }))->toThrow(QueryException::class, $constraint);
})->with([
    // The imports.
    'an import of no kind we have' => [fn (array $r) => DB::table('catalog.imports')->where('id', $r['products'])->update(['kind' => 'PHOTOS']), 'imports_kind'],
    'a products file in a store file\'s state' => [fn (array $r) => DB::table('catalog.imports')->where('id', $r['products'])->update(['state' => 'OPEN']), 'imports_state'],
    'a products file of a store' => [fn (array $r) => DB::table('catalog.imports')->where('id', $r['products'])->update(['store_id' => Fx::storeId('sa')]), 'imports_store_for_store_fill'],
    'a store file with a zip' => [fn (array $r) => DB::table('catalog.imports')->where('id', $r['fill'])->update(['archive' => 'catalog-imports/x.zip']), 'imports_archive_for_products'],
    'a reason without a failure' => [fn (array $r) => DB::table('catalog.imports')->where('id', $r['products'])->update(['failure' => 'Product 1: no.']), 'imports_failure_when_failed'],
    // Its names.
    'a name of no kind we have' => [fn (array $r) => DB::table('catalog.import_names')->where('id', $r['brand'])->update(['kind' => 'SHOP']), 'import_names_kind'],
    'a decision we do not make' => [fn (array $r) => DB::table('catalog.import_names')->where('id', $r['brand'])->update(['decision' => 'MAYBE']), 'import_names_decision'],
    'an attribute on a brand' => [fn (array $r) => DB::table('catalog.import_names')->where('id', $r['brand'])->update(['attribute' => 'Width']), 'import_names_value_attribute'],
    'a job on a brand' => [fn (array $r) => DB::table('catalog.import_names')->where('id', $r['brand'])->update(['attribute_kind' => 'FILTERABLE']), 'import_names_attribute_kind'],
    'one we have, naming none' => [fn (array $r) => DB::table('catalog.import_names')->where('id', $r['brand'])->update(['decision' => 'EXISTING']), 'import_names_existing_target'],
    'one to create, without its names' => [fn (array $r) => DB::table('catalog.import_names')->where('id', $r['category'])->update(['decision' => 'CREATE']), 'import_names_create_names'],
    'one refused, naming one' => [fn (array $r) => DB::table('catalog.import_names')->where('id', $r['brand'])->update(['decision' => 'REFUSE', 'target_id' => strtolower((string) Str::ulid())]), 'import_names_refused_target'],
    'a name no product uses' => [fn (array $r) => DB::table('catalog.import_names')->where('id', $r['brand'])->update(['products' => 0]), 'import_names_products'],
    'matches below none' => [fn (array $r) => DB::table('catalog.import_names')->where('id', $r['brand'])->update(['matches' => -1]), 'import_names_matches'],
    'an address for a brand' => [fn (array $r) => DB::table('catalog.import_names')->where('id', $r['brand'])->update(['slug_en' => 'blumm']), 'import_names_slugs_for_created_categories'],
    // A CHECK whose test comes out NULL lets the row through (lesson 35): each of these was one.
    'an address for a category not decided' => [fn (array $r) => DB::table('catalog.import_names')->where('id', $r['category'])->update(['slug_en' => 'kitchens']), 'import_names_slugs_for_created_categories'],
    // Its products.
    'a code decision we do not make' => [fn (array $r) => DB::table('catalog.import_products')->where('id', $r['product'])->update(['decision' => 'MERGE']), 'import_products_decision'],
    'new codes, given none' => [fn (array $r) => DB::table('catalog.import_products')->where('id', $r['product'])->update(['decision' => 'RECODE']), 'import_products_recode'],
    'keep on sale, with no update' => [fn (array $r) => DB::table('catalog.import_products')->where('id', $r['product'])->update(['sale' => 'KEEP']), 'import_products_sale'],
    'a state we do not have' => [fn (array $r) => DB::table('catalog.import_products')->where('id', $r['product'])->update(['state' => 'GONE']), 'import_products_state'],
    'left out, saying not why' => [fn (array $r) => DB::table('catalog.import_products')->where('id', $r['product'])->update(['state' => 'REFUSED']), 'import_products_refused'],
    'a place in the file before the first' => [fn (array $r) => DB::table('catalog.import_products')->where('id', $r['product'])->update(['number' => 0]), 'import_products_number'],
    'the file\'s product not an object' => [fn (array $r) => DB::table('catalog.import_products')->where('id', $r['product'])->update(['data' => '[]']), 'import_products_data_object'],
    'the changed product not an object' => [fn (array $r) => DB::table('catalog.import_products')->where('id', $r['product'])->update(['edited' => '[]']), 'import_products_edited_object'],
    // A store file's items.
    'letters in an item\'s code' => [fn (array $r) => DB::table('catalog.store_fill_items')->where('id', $r['item'])->update(['code' => 'A1']), 'store_fill_items_code'],
    'an item\'s state we do not have' => [fn (array $r) => DB::table('catalog.store_fill_items')->where('id', $r['item'])->update(['state' => 'GONE']), 'store_fill_items_state'],
    'a stock below none' => [fn (array $r) => DB::table('catalog.store_fill_items')->where('id', $r['item'])->update(['stock' => -1]), 'store_fill_items_stock'],
    'an item before the first' => [fn (array $r) => DB::table('catalog.store_fill_items')->where('id', $r['item'])->update(['number' => 0]), 'store_fill_items_number'],
    // The listing's rows and the searches.
    'a listing row in no language of ours' => [fn (array $r) => DB::table('catalog.listing')->where('product_id', $r['listed'])->where('locale', 'en')->update(['locale' => 'fr']), 'listing_locale'],
    'a card photo without its file, or a file without its photo' => [fn (array $r) => DB::table('catalog.listing')->where('product_id', $r['listed'])->where('locale', 'en')->update(
        DB::table('catalog.listing')->where('product_id', $r['listed'])->where('locale', 'en')->value('card_media_id') === null ? ['card_photo' => '{"src": "x"}'] : ['card_photo' => null],
    ), 'listing_card_photo_with_media'],
    'a search in no language of ours' => [fn (array $r) => DB::table('catalog.search_log')->update(['locale' => 'fr']), 'search_log_locale'],
    'a search of nothing' => [fn (array $r) => DB::table('catalog.search_log')->update(['query' => '   ']), 'search_log_query_present'],
    'results below none' => [fn (array $r) => DB::table('catalog.search_log')->update(['results' => -1]), 'search_log_results_range'],
]);
