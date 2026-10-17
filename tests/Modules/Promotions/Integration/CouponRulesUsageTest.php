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
use Modules\Catalog\Domain\Exception\CatalogItemInUse;
use Modules\Catalog\Public\Enums\CatalogItem;
use Modules\Promotions\Infrastructure\Eloquent\CouponRulesUsage;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Promotions\Support\PromotionsFixtures as Pr;

use function Pest\Laravel\seed;

/*
| Promotions' answer to Catalog's usage check (promotions.md §1.2; catalog.md amendment 19): a
| category, brand or product a coupon's include or exclude list names is refused by Catalog with what
| uses it — never a database error, never a rule dropped — and deleted once no coupon names it.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    Fx::actAsStaff(Fx::staff(superAdmin: true));
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

it('answers every coupon naming any of the items, in one query, and nothing for a variant yet', function () {
    $doors = Px::category('Doors');
    $hinges = Px::category('Hinges');
    $first = Pr::coupon('FIRST-1', rules: [['INCLUDE', 'category', $doors]]);
    $second = Pr::coupon('SECOND-2', rules: [['EXCLUDE', 'category', $hinges], ['INCLUDE', 'category', $doors]]);
    $usage = app(CouponRulesUsage::class);

    $uses = $usage->usesOf(CatalogItem::Category, [$doors, $hinges]);

    expect(array_map(static fn ($use): array => [$use->module, $use->kind, $use->id, $use->itemId], $uses))->toEqualCanonicalizing([
        ['promotions', 'coupon', $first, $doors],
        ['promotions', 'coupon', $second, $hinges],
        ['promotions', 'coupon', $second, $doors],
    ])
        ->and($usage->usesOf(CatalogItem::Category, [Pr::orderId()]))->toBe([])
        ->and($usage->usesOf(CatalogItem::Variant, [$doors]))->toBe([])
        ->and($usage->usesOf(CatalogItem::Brand, []))->toBe([]);
});
