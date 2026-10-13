<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Command\AddCategory\AddCategory;
use Modules\Catalog\Application\Command\AddCategory\AddCategoryHandler;
use Modules\Catalog\Application\Command\AddVariant\AddVariant;
use Modules\Catalog\Application\Command\AddVariant\AddVariantHandler;
use Modules\Catalog\Application\Command\DeleteAttribute\DeleteAttribute;
use Modules\Catalog\Application\Command\DeleteAttribute\DeleteAttributeHandler;
use Modules\Catalog\Application\Command\DeleteAttributeValue\DeleteAttributeValue;
use Modules\Catalog\Application\Command\DeleteAttributeValue\DeleteAttributeValueHandler;
use Modules\Catalog\Application\Command\DeleteBrand\DeleteBrand;
use Modules\Catalog\Application\Command\DeleteBrand\DeleteBrandHandler;
use Modules\Catalog\Application\Command\DeleteCategory\DeleteCategory;
use Modules\Catalog\Application\Command\DeleteCategory\DeleteCategoryHandler;
use Modules\Catalog\Application\Command\DeleteWarranty\DeleteWarranty;
use Modules\Catalog\Application\Command\DeleteWarranty\DeleteWarrantyHandler;
use Modules\Catalog\Application\Command\EditAttribute\EditAttribute;
use Modules\Catalog\Application\Command\EditAttribute\EditAttributeHandler;
use Modules\Catalog\Application\Command\EditProductDetails\EditProductDetails;
use Modules\Catalog\Application\Command\EditProductDetails\EditProductDetailsHandler;
use Modules\Catalog\Application\Command\MoveCategory\MoveCategory;
use Modules\Catalog\Application\Command\MoveCategory\MoveCategoryHandler;
use Modules\Catalog\Domain\Exception\AttributeKindLocked;
use Modules\Catalog\Domain\Exception\BrandInUse;
use Modules\Catalog\Domain\Exception\CategoryHoldsProducts;
use Modules\Catalog\Domain\Exception\CategoryNotEmpty;
use Modules\Catalog\Domain\Exception\ListItemInUse;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogFixtures as Cx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;

use function Pest\Laravel\seed;

/*
| What a product keeps the lists from doing (catalog.md §1.5–§1.9, amendments 3(i), (k), 16(b)):
| deleting a brand, category, warranty, attribute or value a product uses — an attribute it makes its
| variants of among them; giving a category that holds products a sub-category; changing an
| attribute's job once variants carry details of it. Each asked after the list row is locked.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    Cx::actAsStaffWith(CatalogPermissions::sharedLists());
});

/**
 * @param  array<string, mixed>  $details  the product's details to set
 */
function catalogInUseProduct(array $details = []): string
{
    $id = Px::product();
    $product = app(ProductRepository::class)->find($id) ?? throw new LogicException('No such product.');

    Fx::asSystem(fn () => app(EditProductDetailsHandler::class)->handle(new EditProductDetails(...[
        'productId' => $id, 'nameAr' => $product->name()->ar, 'nameEn' => $product->name()->en, 'brandId' => $product->brandId(), ...$details,
    ])));

    return $id;
}

it('keeps a brand a product carries, until no product does', function () {
    $brand = Px::brand();
    $product = Px::product(brandId: $brand);

    expect(fn () => app(DeleteBrandHandler::class)->handle(new DeleteBrand($brand)))->toThrow(BrandInUse::class);

    DB::table('catalog.products')->where('id', $product)->delete();
    app(DeleteBrandHandler::class)->handle(new DeleteBrand($brand));

    expect(DB::table('catalog.brands')->where('id', $brand)->exists())->toBeFalse();
});

it('keeps a category that holds a product, and gives it no sub-category, here or by a move', function () {
    $doors = Px::category('Doors');
    catalogInUseProduct(['categoryId' => $doors]);
    $hinges = Px::category('Hinges');

    expect(fn () => app(DeleteCategoryHandler::class)->handle(new DeleteCategory($doors)))->toThrow(CategoryNotEmpty::class)
        ->and(fn () => app(AddCategoryHandler::class)->handle(new AddCategory('قسم فرعي', 'Sub', $doors)))->toThrow(CategoryHoldsProducts::class)
        ->and(fn () => app(MoveCategoryHandler::class)->handle(new MoveCategory($hinges, $doors)))->toThrow(CategoryHoldsProducts::class)
        ->and(DB::table('catalog.categories')->where('parent_id', $doors)->count())->toBe(0);
});

it('keeps a warranty a product takes, and an attribute a product makes its variants of', function () {
    $warranty = Px::warranty();
    $width = Px::attribute();
    $product = catalogInUseProduct(['warrantyId' => $warranty]);
    Px::variantAttributes($product, [$width]);

    expect(fn () => app(DeleteWarrantyHandler::class)->handle(new DeleteWarranty($warranty)))->toThrow(ListItemInUse::class)
        ->and(fn () => app(DeleteAttributeHandler::class)->handle(new DeleteAttribute($width)))->toThrow(ListItemInUse::class);
});

it('keeps a value a variant takes, and an attribute a variant has a detail of', function () {
    $width = Px::attribute();
    $sixty = Px::value($width, '60 cm');
    $eighty = Px::value($width, '80 cm');
    $product = catalogInUseProduct();
    Px::variantAttributes($product, [$width]);
    Px::variant($product, '1304', [$width => $sixty]);
    $material = Px::attribute('Material', 'INFORMATIONAL');
    Fx::asSystem(fn () => app(AddVariantHandler::class)->handle(new AddVariant(Px::product(), '1500', details: [$material => ['number' => '3']])));

    expect(fn () => app(DeleteAttributeValueHandler::class)->handle(new DeleteAttributeValue($sixty)))->toThrow(ListItemInUse::class)
        ->and(fn () => app(DeleteAttributeHandler::class)->handle(new DeleteAttribute($material)))->toThrow(ListItemInUse::class);

    app(DeleteAttributeValueHandler::class)->handle(new DeleteAttributeValue($eighty));

    expect(DB::table('catalog.attribute_values')->where('id', $eighty)->exists())->toBeFalse();
});

it('keeps a details attribute\'s job once a variant carries a detail of it', function () {
    $material = Px::attribute('Material', 'INFORMATIONAL');
    $free = Px::attribute('Finish', 'INFORMATIONAL');
    Fx::asSystem(fn () => app(AddVariantHandler::class)->handle(new AddVariant(Px::product(), '1500', details: [$material => ['text_ar' => 'جلد', 'text_en' => 'Leather']])));

    expect(fn () => app(EditAttributeHandler::class)->handle(new EditAttribute($material, 'خاصية', 'Material', 'FILTERABLE')))->toThrow(AttributeKindLocked::class);

    app(EditAttributeHandler::class)->handle(new EditAttribute($free, 'خاصية', 'Finish', 'FILTERABLE'));

    expect(DB::table('catalog.attributes')->where('id', $free)->value('kind'))->toBe('FILTERABLE');
});
