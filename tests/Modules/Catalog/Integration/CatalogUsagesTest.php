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
use Modules\Catalog\Domain\Exception\CatalogItemInUse;
use Modules\Catalog\Public\Contracts\CatalogUsage;
use Modules\Catalog\Public\Contracts\CatalogUsages;
use Modules\Catalog\Public\Dto\CatalogUseDto;
use Modules\Catalog\Public\Enums\CatalogItem;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;

use function Pest\Laravel\seed;

/*
| Catalog's usage check (amendment 19; promotions.md §1.2): before deleting a category, a brand, a
| draft product or a draft variant, Catalog asks every module registered with it where the item is
| used, and refuses with what uses it — nothing deleted. A stand-in module, registered by this test
| only, answers for the items it is told to hold.
*/

/** A module of the test's own, holding whatever items the test puts in it. */
final class CatalogUsagesStandIn implements CatalogUsage
{
    /** @var array<string, list<string>> item kind => ids held */
    public static array $holds = [];

    /** @var list<array{0: CatalogItem, 1: list<string>}> what Catalog asked */
    public static array $asked = [];

    public function usesOf(CatalogItem $item, array $ids): array
    {
        self::$asked[] = [$item, $ids];

        return array_map(
            static fn (string $id): CatalogUseDto => new CatalogUseDto('standin', 'thing', 'held-'.$id, $item, $id),
            array_values(array_intersect($ids, self::$holds[$item->value] ?? [])),
        );
    }
}

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    CatalogUsagesStandIn::$holds = [];
    CatalogUsagesStandIn::$asked = [];
    app(CatalogUsages::class)->register('standin', CatalogUsagesStandIn::class);
    Fx::actAsStaff(Fx::staff(superAdmin: true));
});

function catalogUsagesHold(CatalogItem $item, string $id): void
{
    CatalogUsagesStandIn::$holds[$item->value][] = $id;
}

it('refuses to delete a category, a brand, a draft product or a draft variant another module uses, and deletes nothing', function (Closure $make) {
    [$table, $id, $delete, $item, $itemId] = $make();

    $refused = null;

    try {
        $delete();
    } catch (CatalogItemInUse $error) {
        $refused = $error;
    }

    expect($refused?->modules)->toBe(['standin'])
        ->and($refused?->uses)->toBe(1)
        ->and($refused?->context())->toBe(['modules' => 'standin', 'uses' => 1, 'item' => $item->value, 'item_id' => $itemId])
        ->and(DB::table("catalog.{$table}")->where('id', $id)->exists())->toBeTrue();
})->with([
    'a category' => [function () {
        $category = Px::category('Doors');
        catalogUsagesHold(CatalogItem::Category, $category);

        return ['categories', $category, fn () => app(DeleteCategoryHandler::class)->handle(new DeleteCategory($category)), CatalogItem::Category, $category];
    }],
    'a brand' => [function () {
        $brand = Px::brand('Hafele');
        catalogUsagesHold(CatalogItem::Brand, $brand);

        return ['brands', $brand, fn () => app(DeleteBrandHandler::class)->handle(new DeleteBrand($brand)), CatalogItem::Brand, $brand];
    }],
    'a draft product' => [function () {
        $product = Px::product('Hinge');
        catalogUsagesHold(CatalogItem::Product, $product);

        return ['products', $product, fn () => app(DeleteDraftProductHandler::class)->handle(new DeleteDraftProduct($product)), CatalogItem::Product, $product];
    }],
    'a draft product whose variant is used' => [function () {
        $product = Px::product('Hinge');
        $variant = Px::variant($product, '7100001');
        catalogUsagesHold(CatalogItem::Variant, $variant);

        return ['products', $product, fn () => app(DeleteDraftProductHandler::class)->handle(new DeleteDraftProduct($product)), CatalogItem::Variant, $variant];
    }],
    'a draft variant' => [function () {
        $variant = Px::variant(Px::product('Hinge'), '7100002');
        catalogUsagesHold(CatalogItem::Variant, $variant);

        return ['variants', $variant, fn () => app(DeleteDraftVariantHandler::class)->handle(new DeleteDraftVariant($variant)), CatalogItem::Variant, $variant];
    }],
]);

it('deletes what no module uses, having asked about it', function () {
    $category = Px::category('Doors');
    $product = Px::product('Hinge');
    $variant = Px::variant($product, '7100003');
    // Held by the stand-in, but a different item: no answer for these.
    catalogUsagesHold(CatalogItem::Category, Px::category('Other'));

    $brand = Px::brand('Hafele');
    $lone = Px::variant(Px::product('Hinge'), '7100004');

    app(DeleteCategoryHandler::class)->handle(new DeleteCategory($category));
    app(DeleteDraftProductHandler::class)->handle(new DeleteDraftProduct($product));
    app(DeleteBrandHandler::class)->handle(new DeleteBrand($brand));
    app(DeleteDraftVariantHandler::class)->handle(new DeleteDraftVariant($lone));

    expect(DB::table('catalog.categories')->where('id', $category)->exists())->toBeFalse()
        ->and(DB::table('catalog.brands')->where('id', $brand)->exists())->toBeFalse()
        ->and(DB::table('catalog.variants')->where('id', $lone)->exists())->toBeFalse()
        ->and(DB::table('catalog.products')->where('id', $product)->exists())->toBeFalse()
        ->and(CatalogUsagesStandIn::$asked)->toContain([CatalogItem::Category, [$category]])
        ->and(CatalogUsagesStandIn::$asked)->toContain([CatalogItem::Product, [$product]])
        ->and(CatalogUsagesStandIn::$asked)->toContain([CatalogItem::Variant, [$variant]]);
});

it('takes only a usage class, and each once', function () {
    expect(fn () => app(CatalogUsages::class)->register('standin', CatalogUsagesStandIn::class))->toThrow(LogicException::class)
        ->and(fn () => app(CatalogUsages::class)->register('standin', stdClass::class))->toThrow(LogicException::class);
});
