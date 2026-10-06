<?php

declare(strict_types=1);

use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Command\AddAttribute\AddAttribute;
use Modules\Catalog\Application\Command\AddAttribute\AddAttributeHandler;
use Modules\Catalog\Application\Command\AddAttributeValue\AddAttributeValue;
use Modules\Catalog\Application\Command\AddAttributeValue\AddAttributeValueHandler;
use Modules\Catalog\Application\Command\AddBrand\AddBrand;
use Modules\Catalog\Application\Command\AddBrand\AddBrandHandler;
use Modules\Catalog\Application\Command\AddCategory\AddCategory;
use Modules\Catalog\Application\Command\AddCategory\AddCategoryHandler;
use Modules\Catalog\Application\Command\AddLabel\AddLabel;
use Modules\Catalog\Application\Command\AddLabel\AddLabelHandler;
use Modules\Catalog\Application\Command\AddWarranty\AddWarranty;
use Modules\Catalog\Application\Command\AddWarranty\AddWarrantyHandler;
use Modules\Catalog\Domain\Repository\AttributeRepository;
use Modules\Catalog\Domain\Repository\BrandRepository;
use Modules\Catalog\Domain\Repository\CategoryRepository;
use Modules\Catalog\Domain\Repository\LabelRepository;
use Modules\Catalog\Domain\Repository\WarrantyRepository;
use Modules\Catalog\Domain\Repository\WordPairRepository;
use Tests\Modules\Catalog\Support\CatalogFixtures as Cx;

use function Pest\Laravel\seed;

/*
| The database's own refusals behind the code (handoff §5.3; review of step 2): each named CHECK,
| index or key of the lists' migration that no handler test reaches, refused when a row is written
| past the code. And an id that is not a ULID never reaches the database at all.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    Cx::actAsStaffWith(CatalogPermissions::sharedLists());
});

/**
 * @return array{blocks: list<array<string, mixed>>}
 */
function catalogConstraintsTerms(): array
{
    return ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Terms']]]]];
}

it('refuses what the code would never write', function (Closure $write, string $constraint) {
    expect(fn () => DB::transaction(function () use ($write): bool {
        $write();

        return true;
    }))->toThrow(QueryException::class, $constraint);
})->with([
    'a brand name of spaces' => [function () {
        $id = app(AddBrandHandler::class)->handle(new AddBrand('بلوم', 'Blum', 'DISTRIBUTOR'));
        DB::table('catalog.brands')->where('id', $id)->update(['name_ar' => '  ']);
    }, 'brands_name_ar_present'],
    'a brand name on two lines' => [function () {
        $id = app(AddBrandHandler::class)->handle(new AddBrand('بلوم', 'Blum', 'DISTRIBUTOR'));
        DB::table('catalog.brands')->where('id', $id)->update(['name_en' => "Blum\nHinges"]);
    }, 'brands_name_en_one_line'],
    'a brand position past the range' => [function () {
        $id = app(AddBrandHandler::class)->handle(new AddBrand('بلوم', 'Blum', 'DISTRIBUTOR'));
        DB::table('catalog.brands')->where('id', $id)->update(['position' => 10001]);
    }, 'brands_position_range'],
    'a brand description in one language' => [function () {
        $id = app(AddBrandHandler::class)->handle(new AddBrand('بلوم', 'Blum', 'DISTRIBUTOR'));
        DB::table('catalog.brands')->where('id', $id)->update(['description_ar' => json_encode(catalogConstraintsTerms())]);
    }, 'brands_description_both'],
    'a brand description that is not an object' => [function () {
        $id = app(AddBrandHandler::class)->handle(new AddBrand('بلوم', 'Blum', 'DISTRIBUTOR'));
        DB::table('catalog.brands')->where('id', $id)->update(['description_ar' => '[]', 'description_en' => '[]']);
    }, 'brands_description_object'],
    'a Latin word in an Arabic slug' => [function () {
        $id = app(AddBrandHandler::class)->handle(new AddBrand('بلوم', 'Blum', 'DISTRIBUTOR'));
        DB::table('catalog.brand_slugs')->insert(['locale' => 'ar', 'slug' => 'بلوم-blum', 'brand_id' => $id, 'is_current' => false, 'created_at' => now()]);
    }, 'brand_slugs_shape'],
    'a capital in an English slug' => [function () {
        $id = app(AddBrandHandler::class)->handle(new AddBrand('بلوم', 'Blum', 'DISTRIBUTOR'));
        DB::table('catalog.brand_slugs')->insert(['locale' => 'en', 'slug' => 'Blum-Hinges', 'brand_id' => $id, 'is_current' => false, 'created_at' => now()]);
    }, 'brand_slugs_shape'],
    'two current slugs in one language' => [function () {
        $id = app(AddBrandHandler::class)->handle(new AddBrand('بلوم', 'Blum', 'DISTRIBUTOR'));
        DB::table('catalog.brand_slugs')->insert(['locale' => 'en', 'slug' => 'blum-hinges', 'brand_id' => $id, 'is_current' => true, 'created_at' => now()]);
    }, 'brand_slugs_one_current'],
    'a punctuation mark in a category\'s Arabic slug' => [function () {
        $id = app(AddCategoryHandler::class)->handle(new AddCategory('مطابخ', 'Kitchens'));
        DB::table('catalog.category_slugs')->insert(['locale' => 'ar', 'slug' => 'مطابخ/جديدة', 'category_id' => $id, 'is_current' => false, 'created_at' => now()]);
    }, 'category_slugs_shape'],
    'a place in a store\'s menu past the range' => [function () {
        $id = app(AddCategoryHandler::class)->handle(new AddCategory('مطابخ', 'Kitchens'));
        DB::table('catalog.store_category_ranks')->where('category_id', $id)->update(['rank' => 10001]);
    }, 'store_category_ranks_rank_range'],
    'an attribute position below the range' => [function () {
        $id = app(AddAttributeHandler::class)->handle(new AddAttribute('العرض', 'Width', 'VARIANT'));
        DB::table('catalog.attributes')->where('id', $id)->update(['position' => -1]);
    }, 'attributes_position_range'],
    'a value position below the range' => [function () {
        $attribute = app(AddAttributeHandler::class)->handle(new AddAttribute('العرض', 'Width', 'VARIANT'));
        $id = app(AddAttributeValueHandler::class)->handle(new AddAttributeValue($attribute, '300 مم', '300 mm'));
        DB::table('catalog.attribute_values')->where('id', $id)->update(['position' => -1]);
    }, 'attribute_values_position_range'],
    'an Arabic label of three words' => [function () {
        $id = app(AddLabelHandler::class)->handle(new AddLabel('جديد', 'New', 'blue'));
        DB::table('catalog.labels')->where('id', $id)->update(['name_ar' => 'وصل حديثا جدا']);
    }, 'labels_name_ar_words'],
    'a label position past the range' => [function () {
        $id = app(AddLabelHandler::class)->handle(new AddLabel('جديد', 'New', 'blue'));
        DB::table('catalog.labels')->where('id', $id)->update(['position' => 10001]);
    }, 'labels_position_range'],
    'warranty terms that are not an object' => [function () {
        $id = app(AddWarrantyHandler::class)->handle(new AddWarranty('سنة', 'One year', catalogConstraintsTerms(), catalogConstraintsTerms(), 12));
        DB::table('catalog.warranties')->where('id', $id)->update(['terms_ar' => '"terms"']);
    }, 'warranties_terms_object'],
    'a word pair with a blank word' => [function () {
        DB::table('catalog.word_pairs')->insert(['id' => strtolower((string) Str::ulid()), 'word_a' => ' ', 'word_b' => 'hinge', 'created_at' => now()]);
    }, 'word_pairs_present'],
]);

it('answers an id that is not a ULID as not found without asking the database', function () {
    $queries = Cx::recordQueries();

    expect(app(BrandRepository::class)->find('not-an-id'))->toBeNull()
        ->and(app(CategoryRepository::class)->find('blum'))->toBeNull()
        ->and(app(CategoryRepository::class)->idsBelow("01j8z3k4m5n6p7q8r9s0t1v2w3' OR 1=1"))->toBe([])
        ->and(app(AttributeRepository::class)->find('0'))->toBeNull()
        ->and(app(AttributeRepository::class)->findValue(''))->toBeNull()
        ->and(app(AttributeRepository::class)->findSet('x'))->toBeNull()
        ->and(app(LabelRepository::class)->find('not-an-id'))->toBeNull()
        ->and(app(WarrantyRepository::class)->find('not-an-id'))->toBeNull()
        ->and(app(WordPairRepository::class)->find('not-an-id'))->toBeNull()
        ->and((array) $queries)->toBe([]);
});
