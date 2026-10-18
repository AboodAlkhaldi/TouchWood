<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Application\Command\DeleteBrand\DeleteBrand;
use Modules\Catalog\Application\Command\DeleteBrand\DeleteBrandHandler;
use Modules\Catalog\Application\Command\DeleteCategory\DeleteCategory;
use Modules\Catalog\Application\Command\DeleteCategory\DeleteCategoryHandler;
use Modules\Catalog\Application\Command\DeleteDraftProduct\DeleteDraftProduct;
use Modules\Catalog\Application\Command\DeleteDraftProduct\DeleteDraftProductHandler;
use Modules\Catalog\Application\Command\DeleteDraftVariant\DeleteDraftVariant;
use Modules\Catalog\Application\Command\DeleteDraftVariant\DeleteDraftVariantHandler;
use Modules\Catalog\Application\Command\DeleteImportedProducts\DeleteImportedProducts;
use Modules\Catalog\Application\Command\DeleteImportedProducts\DeleteImportedProductsHandler;
use Modules\Catalog\Domain\Exception\CatalogItemInUse;
use Modules\Catalog\Domain\Repository\CategoryRepository;
use Modules\Catalog\Domain\Repository\VariantRepository;
use Modules\Catalog\Public\Enums\CatalogItem;
use Modules\Promotions\Infrastructure\Eloquent\PromotionsCatalogUsage;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogFixtures as Cx;
use Tests\Modules\Catalog\Support\CatalogImports as Ix;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Promotions\Support\PromotionsFixtures as Pr;

use function Pest\Laravel\seed;

/*
| Promotions' answer to Catalog's usage check (promotions.md §1.2; catalog.md amendment 19): a
| category, brand or product a coupon's include or exclude list names, and a variant a gift level
| gives, is refused by Catalog with what uses it — never a database error, never a rule or a level
| dropped — and deleted once nothing names it.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    Fx::actAsStaff(Fx::staff(superAdmin: true));
});

afterEach(function () {
    // The import's uploaded files, as Catalog's import tests remove theirs.
    Ix::cleanUp();
});

it('makes Catalog refuse to delete what a coupon\'s list names, include or exclude', function (string $kind, Closure $make) {
    [$table, $id, $delete] = $make();
    Pr::coupon(rules: [[$kind, match ($table) {
        'categories' => 'category',
        'brands' => 'brand',
        default => 'product',
    }, $id]]);

    expect(fn () => $delete())->toThrow(CatalogItemInUse::class)
        ->and(DB::table("catalog.{$table}")->where('id', $id)->exists())->toBeTrue()
        ->and(DB::table('promotions.coupon_rules')->count())->toBe(1);
})->with(['INCLUDE', 'EXCLUDE'])->with([
    'a category' => [function () {
        $category = Px::category('Doors');

        return ['categories', $category, fn () => app(DeleteCategoryHandler::class)->handle(new DeleteCategory($category))];
    }],
    'a brand' => [function () {
        $brand = Px::brand('Hafele');

        return ['brands', $brand, fn () => app(DeleteBrandHandler::class)->handle(new DeleteBrand($brand))];
    }],
    'a draft product' => [function () {
        $product = Px::product('Hinge');

        return ['products', $product, fn () => app(DeleteDraftProductHandler::class)->handle(new DeleteDraftProduct($product))];
    }],
]);

it('lets Catalog delete it once no coupon names it', function () {
    $category = Px::category('Doors');
    $coupon = Pr::coupon(rules: [['INCLUDE', 'category', $category]]);
    DB::table('promotions.coupons')->where('id', $coupon)->delete();

    app(DeleteCategoryHandler::class)->handle(new DeleteCategory($category));

    expect(DB::table('catalog.categories')->where('id', $category)->exists())->toBeFalse();
});

it('makes the import\'s "delete all" refuse, naming the product a coupon names, and delete nothing', function () {
    $import = Ix::uploadProducts([Ix::product('6100'), Ix::product('6200')]);
    Ix::decideNames($import);
    Ix::bringIn($import);
    $named = Ix::broughtIn($import, 2);
    Pr::coupon(rules: [['EXCLUDE', 'product', $named]]);

    $refused = Pr::thrown(fn () => app(DeleteImportedProductsHandler::class)->handle(new DeleteImportedProducts($import, null)), CatalogItemInUse::class);

    expect($refused->context())->toBe(['modules' => 'promotions', 'uses' => 1, 'item' => 'product', 'item_id' => $named])
        ->and(DB::table('catalog.products')->whereIn('id', [Ix::broughtIn($import, 1), $named])->count())->toBe(2);
});

it('turns a reference written after the check into the same refusal, and leaves the caller\'s transaction usable', function () {
    $category = Px::category('Doors');
    Pr::coupon(rules: [['INCLUDE', 'category', $category]]);

    // The repository's own delete, as if the check had run before the rule was written.
    $refused = Pr::thrown(fn () => app(CategoryRepository::class)->delete($category), CatalogItemInUse::class);

    expect($refused->modules)->toBe(['coupon_rules'])
        ->and($refused->context()['item_id'])->toBe($category)
        ->and(DB::table('catalog.categories')->where('id', $category)->exists())->toBeTrue();
});

it('answers every coupon naming any of the items, in one query', function () {
    $doors = Px::category('Doors');
    $hinges = Px::category('Hinges');
    $first = Pr::coupon('FIRST-1', rules: [['INCLUDE', 'category', $doors]]);
    $second = Pr::coupon('SECOND-2', rules: [['EXCLUDE', 'category', $hinges], ['INCLUDE', 'category', $doors]]);
    $usage = app(PromotionsCatalogUsage::class);

    $queries = Cx::recordQueries();
    $uses = $usage->usesOf(CatalogItem::Category, [$doors, $hinges]);
    expect(count($queries))->toBe(1);

    expect(array_map(static fn ($use): array => [$use->module, $use->kind, $use->id, $use->itemId], $uses))->toEqualCanonicalizing([
        ['promotions', 'coupon', $first, $doors],
        ['promotions', 'coupon', $second, $hinges],
        ['promotions', 'coupon', $second, $doors],
    ])
        ->and($usage->usesOf(CatalogItem::Category, [Pr::orderId()]))->toBe([])
        ->and($usage->usesOf(CatalogItem::Variant, [$doors]))->toBe([])
        ->and($usage->usesOf(CatalogItem::Brand, []))->toBe([]);
});

it('makes Catalog refuse to delete a variant a gift level gives, alone or with its draft product', function () {
    // Written straight in: staff can only choose a ready product's variant, whose draft is gone.
    $product = Px::product('Pen');
    $pen = Px::variant($product, '7002');
    $level = Pr::giftLevel($pen, 1000_00);

    $alone = Pr::thrown(fn () => app(DeleteDraftVariantHandler::class)->handle(new DeleteDraftVariant($pen)), CatalogItemInUse::class);
    $withProduct = Pr::thrown(fn () => app(DeleteDraftProductHandler::class)->handle(new DeleteDraftProduct($product)), CatalogItemInUse::class);

    expect($alone->context())->toBe(['modules' => 'promotions', 'uses' => 1, 'item' => 'variant', 'item_id' => $pen])
        ->and($withProduct->context()['item'])->toBe('variant')
        ->and(DB::table('catalog.variants')->where('id', $pen)->exists())->toBeTrue()
        ->and(app(PromotionsCatalogUsage::class)->usesOf(CatalogItem::Variant, [$pen])[0]->kind)->toBe('gift_level')
        ->and(app(PromotionsCatalogUsage::class)->usesOf(CatalogItem::Variant, [$pen])[0]->id)->toBe($level);

    DB::table('promotions.gift_levels')->where('id', $level)->delete();
    app(DeleteDraftVariantHandler::class)->handle(new DeleteDraftVariant($pen));

    expect(DB::table('catalog.variants')->where('id', $pen)->exists())->toBeFalse();
});

it('turns a gift level written after the check into the same refusal for its variant', function () {
    $pen = Px::variant(Px::product('Pen'), '7003');
    Pr::giftLevel($pen, 1000_00);

    // The repository's own delete, as if the check had run before the level was written.
    $refused = Pr::thrown(fn () => app(VariantRepository::class)->delete($pen), CatalogItemInUse::class);

    expect($refused->modules)->toBe(['gift_levels'])
        ->and($refused->context()['item_id'])->toBe($pen)
        ->and(DB::table('catalog.variants')->where('id', $pen)->exists())->toBeTrue();
});
