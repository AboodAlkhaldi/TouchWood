<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Command\ArchiveProduct\ArchiveProduct;
use Modules\Catalog\Application\Command\ArchiveProduct\ArchiveProductHandler;
use Modules\Catalog\Application\Command\CreateProduct\CreateProduct;
use Modules\Catalog\Application\Command\CreateProduct\CreateProductHandler;
use Modules\Catalog\Application\Command\DeactivateBrand\DeactivateBrand;
use Modules\Catalog\Application\Command\DeactivateBrand\DeactivateBrandHandler;
use Modules\Catalog\Application\Command\DeactivateCategory\DeactivateCategory;
use Modules\Catalog\Application\Command\DeactivateCategory\DeactivateCategoryHandler;
use Modules\Catalog\Application\Command\DeactivateWarranty\DeactivateWarranty;
use Modules\Catalog\Application\Command\DeactivateWarranty\DeactivateWarrantyHandler;
use Modules\Catalog\Application\Command\DeleteDraftProduct\DeleteDraftProduct;
use Modules\Catalog\Application\Command\DeleteDraftProduct\DeleteDraftProductHandler;
use Modules\Catalog\Application\Command\EditProductDetails\EditProductDetails;
use Modules\Catalog\Application\Command\EditProductDetails\EditProductDetailsHandler;
use Modules\Catalog\Domain\Exception\BrandInactive;
use Modules\Catalog\Domain\Exception\BrandNotFound;
use Modules\Catalog\Domain\Exception\CategoryInactive;
use Modules\Catalog\Domain\Exception\CategoryNotLowest;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Exception\InvalidStageChange;
use Modules\Catalog\Domain\Exception\ListItemInactive;
use Modules\Catalog\Domain\Exception\ProductArchived;
use Modules\Catalog\Domain\Exception\SlugTaken;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStore;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStoreHandler;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogFixtures as Cx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;

use function Pest\Laravel\seed;

/*
| Products (catalog.md §1.1, §4.1, amendment 3): created as drafts by someone holding the job in a
| store that is on - no store asked (amendment 13(f)) -, with the Arabic name at least; their details edited as the product's shared data; a draft deleted whole,
| its slugs and codes free again. Each change under the products' lock, audited by value.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

/**
 * The details form as it stands, with these fields changed.
 *
 * @param  array<string, mixed>  $changes
 */
function catalogProductsEdit(string $productId, array $changes = []): void
{
    $product = app(ProductRepository::class)->find($productId) ?? throw new LogicException('No such product.');

    app(EditProductDetailsHandler::class)->handle(new EditProductDetails(...[
        'productId' => $productId,
        'nameAr' => $product->name()->ar,
        'nameEn' => $product->name()->en,
        'brandId' => $product->brandId(),
        'slugAr' => $product->slugs()->ar->value,
        'slugEn' => $product->slugs()->en?->value,
        'descriptionAr' => $product->descriptionAr()?->toArray(),
        'descriptionEn' => $product->descriptionEn()?->toArray(),
        'categoryId' => $product->categoryId(),
        'warrantyId' => $product->warrantyId(),
        ...$changes,
    ]));
}

/**
 * @return array<string, string>
 */
function catalogProductsSlugs(string $productId): array
{
    return DB::table('catalog.product_slugs')->where('product_id', $productId)->where('is_current', true)->orderBy('locale')->pluck('slug', 'locale')->all();
}

describe('creating', function () {
    it('takes catalog.product.create in some store that is on, and asks no store (amendment 13(f))', function () {
        Cx::actAsStaffWith([CatalogPermissions::PRODUCT_CREATE], ['eg']);

        expect(app(CreateProductHandler::class)->handle(new CreateProduct('درج')))->toBeString();

        Cx::actAsStaffWith([CatalogPermissions::PRODUCT_CREATE]);

        expect(app(CreateProductHandler::class)->handle(new CreateProduct('درج آخر')))->toBeString();

        // The job held only in a store that is off, or not at all: refused, nothing made.
        Cx::actAsStaffWith([CatalogPermissions::PRODUCT_CREATE], ['ae']);
        Fx::asSystem(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore('ae')));

        expect(fn () => app(CreateProductHandler::class)->handle(new CreateProduct('درج ثالث')))->toThrow(Unauthorized::class);

        Cx::actAsStaffWith([CatalogPermissions::PRODUCT_UPDATE]);

        expect(fn () => app(CreateProductHandler::class)->handle(new CreateProduct('درج رابع')))->toThrow(Unauthorized::class)
            ->and(DB::table('catalog.products')->count())->toBe(2);
    });

    it('makes a draft from the Arabic name alone, on the default brand, with no English address yet', function () {
        Cx::actAsStaffWith([CatalogPermissions::PRODUCT_CREATE]);
        $locks = Cx::recordLocks();
        $id = app(CreateProductHandler::class)->handle(new CreateProduct('درج تخزين ملابس'));
        $product = app(ProductRepository::class)->find($id);

        expect($product?->stage()->value)->toBe('DRAFT')
            ->and($product?->name()->en)->toBeNull()
            ->and($product?->brandId())->toBe(DB::table('catalog.brands')->where('is_default', true)->value('id'))
            ->and(catalogProductsSlugs($id))->toBe(['ar' => 'درج-تخزين-ملابس'])
            ->and(Fx::audits('catalog.product.added', $id))->toBe(1)
            ->and(array_values(array_filter((array) $locks, static fn (array $lock): bool => $lock['key'] === 'catalog:products')))->toBe([['key' => 'catalog:products', 'level' => 2]]);
    });

    it('makes both addresses from both names, and refuses an English address without an English name', function () {
        Cx::actAsStaffWith([CatalogPermissions::PRODUCT_CREATE]);
        $id = app(CreateProductHandler::class)->handle(new CreateProduct('مفصلة هادئة', 'Soft Hinge'));

        expect(catalogProductsSlugs($id))->toBe(['ar' => 'مفصلة-هادئة', 'en' => 'soft-hinge'])
            ->and(fn () => app(CreateProductHandler::class)->handle(new CreateProduct('مقبض', slugEn: 'handle')))->toThrow(InvalidCatalogAttribute::class, 'slug_en');
    });

    it('refuses an address another product holds, an unknown brand and a deactivated one', function () {
        Cx::actAsStaffWith([CatalogPermissions::PRODUCT_CREATE]);
        app(CreateProductHandler::class)->handle(new CreateProduct('مفصلة هادئة', 'Soft Hinge'));
        $off = Px::brand('Off');
        Fx::asSystem(fn () => app(DeactivateBrandHandler::class)->handle(new DeactivateBrand($off)));

        expect(fn () => app(CreateProductHandler::class)->handle(new CreateProduct('مفصلة هادئة')))->toThrow(SlugTaken::class)
            ->and(fn () => app(CreateProductHandler::class)->handle(new CreateProduct('مقبض', brandId: '01j8z3k4m5n6p7q8r9s0t1v2w3')))->toThrow(BrandNotFound::class)
            ->and(fn () => app(CreateProductHandler::class)->handle(new CreateProduct('مقبض', brandId: $off)))->toThrow(BrandInactive::class)
            ->and(DB::table('catalog.products')->count())->toBe(1);
    });
});

describe('editing details', function () {
    beforeEach(function () {
        Cx::actAsStaffWith([CatalogPermissions::PRODUCT_UPDATE], ['eg']);
    });

    it('lets anyone holding the job in some store edit a product no store sells yet', function () {
        $id = Px::product(null);
        catalogProductsEdit($id, ['nameEn' => 'Clothes Drawer']);

        expect(app(ProductRepository::class)->find($id)?->name()->en)->toBe('Clothes Drawer')
            ->and(catalogProductsSlugs($id)['en'])->toBe('clothes-drawer');

        Cx::actAsStaffWith([CatalogPermissions::PRODUCT_CREATE]);

        expect(fn () => catalogProductsEdit($id, ['nameEn' => 'Other']))->toThrow(Unauthorized::class);
    });

    it('records only what changed, the description by value, and nothing for an edit that changes nothing', function () {
        $id = Px::product();
        catalogProductsEdit($id);

        expect(Fx::audits('catalog.product.edited', $id))->toBe(0);

        $text = ['blocks' => [['type' => 'paragraph', 'runs' => [['text' => 'Soft close', 'bold' => true]]]]];
        catalogProductsEdit($id, ['descriptionEn' => $text]);
        $changes = (array) json_decode((string) DB::table('platform.audit_entries')->where('action', 'catalog.product.edited')->value('changes'), true);

        expect($changes)->toBe(['description_en' => [null, '{"blocks":[{"type":"paragraph","runs":[{"text":"Soft close","bold":true}]}]}']])
            ->and(app(ProductRepository::class)->find($id)?->descriptionEn()?->toArray())->toBe($text);
    });

    it('puts a product only in an active category with no sub-categories', function () {
        $id = Px::product();
        $kitchens = Px::category('Kitchens');
        $cabinets = Px::category('Cabinets', $kitchens);
        $off = Px::category('Off');
        Fx::asSystem(fn () => app(DeactivateCategoryHandler::class)->handle(new DeactivateCategory($off)));

        expect(fn () => catalogProductsEdit($id, ['categoryId' => $kitchens]))->toThrow(CategoryNotLowest::class)
            ->and(fn () => catalogProductsEdit($id, ['categoryId' => $off]))->toThrow(CategoryInactive::class);

        catalogProductsEdit($id, ['categoryId' => strtoupper($cabinets)]);

        expect(app(ProductRepository::class)->find($id)?->categoryId())->toBe($cabinets);
    });

    it('keeps a brand, category or warranty deactivated since, but takes no newly deactivated one', function () {
        $brand = Px::brand();
        $category = Px::category();
        $warranty = Px::warranty();
        $id = Px::product(brandId: $brand);
        catalogProductsEdit($id, ['categoryId' => $category, 'warrantyId' => $warranty]);
        Fx::asSystem(function () use ($brand, $category, $warranty): void {
            app(DeactivateBrandHandler::class)->handle(new DeactivateBrand($brand, 'HIDE'));
            app(DeactivateCategoryHandler::class)->handle(new DeactivateCategory($category, 'LEAVE'));
            app(DeactivateWarrantyHandler::class)->handle(new DeactivateWarranty($warranty));
        });

        catalogProductsEdit($id, ['nameAr' => 'درج معدل']);
        $other = Px::product();

        expect(app(ProductRepository::class)->find($id)?->name()->ar)->toBe('درج معدل')
            ->and(fn () => catalogProductsEdit($other, ['brandId' => $brand]))->toThrow(BrandInactive::class)
            ->and(fn () => catalogProductsEdit($other, ['categoryId' => $category]))->toThrow(CategoryInactive::class)
            ->and(fn () => catalogProductsEdit($other, ['warrantyId' => $warranty]))->toThrow(ListItemInactive::class);
    });
});

describe('deleting a draft', function () {
    beforeEach(function () {
        Cx::actAsStaffWith([CatalogPermissions::PRODUCT_ARCHIVE, CatalogPermissions::PRODUCT_CREATE, CatalogPermissions::PRODUCT_UPDATE]);
    });

    it('removes it whole, each row audited, and frees its addresses and codes', function () {
        $width = Px::attribute();
        $id = Px::product('Drawer');
        Px::variantAttributes($id, [$width]);
        $variant = Px::variant($id, '1304', [$width => Px::value($width, '60 cm')]);
        $slugs = catalogProductsSlugs($id);

        app(DeleteDraftProductHandler::class)->handle(new DeleteDraftProduct($id));

        expect(DB::table('catalog.products')->where('id', $id)->exists())->toBeFalse()
            ->and(DB::table('catalog.variants')->where('id', $variant)->exists())->toBeFalse()
            ->and(DB::table('catalog.product_codes')->where('code', '1304')->exists())->toBeFalse()
            ->and(Fx::audits('catalog.product.deleted', $id))->toBe(1)
            ->and(Fx::audits('catalog.variant.deleted', $variant))->toBe(1);

        // Another product takes the freed address and code.
        $next = app(CreateProductHandler::class)->handle(new CreateProduct(str_replace('-', ' ', $slugs['ar'])));

        expect(catalogProductsSlugs($next)['ar'])->toBe($slugs['ar'])
            ->and(Px::variant($next, '1304'))->toBeString();
    });

    it('deletes only a draft', function () {
        $id = Px::product();
        DB::table('catalog.products')->where('id', $id)->update(['stage' => 'READY', 'category_id' => Px::category()]);

        expect(fn () => app(DeleteDraftProductHandler::class)->handle(new DeleteDraftProduct($id)))->toThrow(InvalidStageChange::class)
            ->and(DB::table('catalog.products')->where('id', $id)->exists())->toBeTrue();
    });
});

describe('what the database refuses behind the code', function () {
    it('keeps a category and an English name on every ready product, each refused alone', function () {
        // Each row fails one CHECK only (lesson 112).
        $named = Px::product('Drawer');
        $unnamed = Px::product(null);
        $category = Px::category();

        expect(fn () => DB::transaction(fn () => DB::table('catalog.products')->where('id', $named)->update(['stage' => 'READY'])))
            ->toThrow(QueryException::class, 'products_category_when_ready')
            ->and(fn () => DB::transaction(fn () => DB::table('catalog.products')->where('id', $unnamed)->update(['stage' => 'READY', 'category_id' => $category])))
            ->toThrow(QueryException::class, 'products_english_when_ready')
            ->and(fn () => DB::transaction(fn () => DB::table('catalog.products')->where('id', $named)->update(['stage' => 'PUBLISHED', 'category_id' => $category])))
            ->toThrow(QueryException::class, 'products_stage');
    });

    it('refuses deleting a brand a product carries, behind the code', function () {
        $brand = Px::brand();
        Px::product(brandId: $brand);

        expect(fn () => DB::transaction(fn () => DB::table('catalog.brands')->where('id', $brand)->delete()))
            ->toThrow(QueryException::class, 'products_brand');
    });
});

describe('an English name taken away from a draft', function () {
    it('leaves no English address current, holds it from every other product, and writes nothing twice', function () {
        Cx::actAsStaffWith([CatalogPermissions::PRODUCT_UPDATE]);
        $id = Px::product('Drawer');
        $address = catalogProductsSlugs($id)['en'];

        catalogProductsEdit($id, ['nameEn' => null, 'slugEn' => null]);
        $edits = Fx::audits('catalog.product.edited', $id);
        catalogProductsEdit($id);

        expect(catalogProductsSlugs($id))->not->toHaveKey('en')
            ->and(app(ProductRepository::class)->find($id)?->slugs()->en)->toBeNull()
            ->and(Fx::audits('catalog.product.edited', $id))->toBe($edits)
            ->and(fn () => catalogProductsEdit(Px::product('Hinge'), ['slugEn' => $address]))->toThrow(SlugTaken::class);

        catalogProductsEdit($id, ['nameEn' => 'Drawer', 'slugEn' => $address]);

        expect(catalogProductsSlugs($id)['en'])->toBe($address);
    });
});

describe('an archived product', function () {
    it('is never deleted, its codes kept with it', function () {
        Cx::actAsStaffWith([CatalogPermissions::PRODUCT_ARCHIVE]);
        $id = Px::product();
        Px::variant($id, '1001');
        app(ArchiveProductHandler::class)->handle(new ArchiveProduct($id));

        expect(fn () => app(DeleteDraftProductHandler::class)->handle(new DeleteDraftProduct($id)))->toThrow(ProductArchived::class)
            ->and(DB::table('catalog.product_codes')->where('code', '1001')->value('product_id'))->toBe($id);
    });
});
