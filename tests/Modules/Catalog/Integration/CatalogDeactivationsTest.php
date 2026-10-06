<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Command\ActivateBrand\ActivateBrand;
use Modules\Catalog\Application\Command\ActivateBrand\ActivateBrandHandler;
use Modules\Catalog\Application\Command\ActivateCategory\ActivateCategory;
use Modules\Catalog\Application\Command\ActivateCategory\ActivateCategoryHandler;
use Modules\Catalog\Application\Command\ArchiveProduct\ArchiveProduct;
use Modules\Catalog\Application\Command\ArchiveProduct\ArchiveProductHandler;
use Modules\Catalog\Application\Command\DeactivateBrand\DeactivateBrand;
use Modules\Catalog\Application\Command\DeactivateBrand\DeactivateBrandHandler;
use Modules\Catalog\Application\Command\DeactivateCategory\DeactivateCategory;
use Modules\Catalog\Application\Command\DeactivateCategory\DeactivateCategoryHandler;
use Modules\Catalog\Application\Command\EditProductDetails\EditProductDetails;
use Modules\Catalog\Application\Command\EditProductDetails\EditProductDetailsHandler;
use Modules\Catalog\Domain\Exception\BrandInactive;
use Modules\Catalog\Domain\Exception\CategoryInactive;
use Modules\Catalog\Domain\Exception\CategoryNotLowest;
use Modules\Catalog\Domain\Exception\DefaultBrandRequired;
use Modules\Catalog\Domain\Exception\InvalidCatalogAttribute;
use Modules\Catalog\Domain\Repository\BrandRepository;
use Modules\Catalog\Domain\Repository\ProductRepository;
use Modules\Catalog\Public\Events\ProductChanged;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogFixtures as Cx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;

use function Pest\Laravel\seed;

/*
| Deactivating a category or a brand with each product's fate (catalog.md §1.5, §1.6, amendment 4):
| every product reached, in any stage, hidden, left or moved — its own choice, or the one for all;
| one step, all or nothing; activating bringing back exactly what hid with it.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    Cx::actAsStaffWith([CatalogPermissions::CATEGORY_MANAGE, CatalogPermissions::BRAND_MANAGE]);
});

/**
 * A draft placed in this category, on this brand if one is given.
 */
function catalogDeactivationsDraft(string $categoryId, ?string $brandId = null): string
{
    $id = Px::product('Hinge', $brandId);
    $product = app(ProductRepository::class)->find($id) ?? throw new LogicException('No such product.');
    Fx::asSystem(fn () => app(EditProductDetailsHandler::class)->handle(new EditProductDetails($id, $product->name()->ar, $product->name()->en, $product->brandId(), categoryId: $categoryId)));

    return $id;
}

/**
 * @return array{category: ?string, brand: string, hidden_by_category: bool, hidden_by_brand: bool}
 */
function catalogDeactivationsState(string $productId): array
{
    $row = DB::table('catalog.products')->where('id', $productId)->sole();

    return [
        'category' => $row->category_id === null ? null : (string) $row->category_id,
        'brand' => (string) $row->brand_id,
        'hidden_by_category' => (bool) $row->hidden_by_category,
        'hidden_by_brand' => (bool) $row->hidden_by_brand,
    ];
}

/**
 * Kitchens, with Drawers and Sinks below it; Tables elsewhere; a ready drawer, a draft drawer and an
 * archived sink.
 *
 * @return array<string, string>
 */
function catalogDeactivationsTree(): array
{
    $kitchens = Px::category('Kitchens');
    $drawers = Px::category('Drawers', $kitchens);
    $sinks = Px::category('Sinks', $kitchens);
    $tables = Px::category('Tables');
    $ready = Px::ready(categoryId: $drawers)['product'];
    $draft = catalogDeactivationsDraft($drawers);
    $archived = Px::ready(categoryId: $sinks)['product'];
    Fx::asSystem(fn () => app(ArchiveProductHandler::class)->handle(new ArchiveProduct($archived)));

    return compact('kitchens', 'drawers', 'sinks', 'tables', 'ready', 'draft', 'archived');
}

describe('a category deactivated', function () {
    it('gives every product in what goes, in any stage, its fate — its own, or the one for all', function () {
        $t = catalogDeactivationsTree();

        app(DeactivateCategoryHandler::class)->handle(new DeactivateCategory($t['kitchens'], 'HIDE', products: [
            $t['draft'] => ['choice' => 'MOVE', 'move_to' => $t['tables']],
            $t['archived'] => ['choice' => 'LEAVE'],
        ]));

        expect(catalogDeactivationsState($t['ready']))->toMatchArray(['category' => $t['drawers'], 'hidden_by_category' => true])
            ->and(catalogDeactivationsState($t['draft']))->toMatchArray(['category' => $t['tables'], 'hidden_by_category' => false])
            ->and(catalogDeactivationsState($t['archived']))->toMatchArray(['category' => $t['sinks'], 'hidden_by_category' => false])
            ->and(Fx::audits('catalog.product.hidden', $t['ready']))->toBe(1)
            ->and(Fx::audits('catalog.product.moved', $t['draft']))->toBe(1)
            ->and(DB::table('catalog.categories')->whereIn('id', [$t['kitchens'], $t['drawers'], $t['sinks']])->where('is_active', true)->count())->toBe(0);
    });

    it('refuses the whole step when a product has no choice, or a choice reaches no product of it', function () {
        $t = catalogDeactivationsTree();
        $elsewhere = Px::ready(categoryId: $t['tables'])['product'];

        expect(fn () => app(DeactivateCategoryHandler::class)->handle(new DeactivateCategory($t['kitchens'], products: [$t['ready'] => ['choice' => 'HIDE']])))->toThrow(InvalidCatalogAttribute::class, 'products')
            ->and(fn () => app(DeactivateCategoryHandler::class)->handle(new DeactivateCategory($t['kitchens'], 'LEAVE', products: [$elsewhere => ['choice' => 'HIDE']])))->toThrow(InvalidCatalogAttribute::class, 'products')
            ->and(fn () => app(DeactivateCategoryHandler::class)->handle(new DeactivateCategory($t['kitchens'], 'MOVE')))->toThrow(InvalidCatalogAttribute::class, 'move_to')
            ->and(fn () => app(DeactivateCategoryHandler::class)->handle(new DeactivateCategory($t['kitchens'], 'SELL')))->toThrow(InvalidCatalogAttribute::class, 'choice')
            ->and(DB::table('catalog.categories')->where('id', $t['kitchens'])->value('is_active'))->toBeTrue()
            ->and(DB::table('catalog.products')->where('hidden_by_category', true)->count())->toBe(0);
    });

    it('moves only to an active lowest category outside what goes, or nothing happens', function () {
        $t = catalogDeactivationsTree();

        expect(fn () => app(DeactivateCategoryHandler::class)->handle(new DeactivateCategory($t['kitchens'], 'MOVE', $t['sinks'])))->toThrow(CategoryInactive::class)
            ->and(fn () => app(DeactivateCategoryHandler::class)->handle(new DeactivateCategory($t['drawers'], 'MOVE', $t['kitchens'])))->toThrow(CategoryNotLowest::class)
            ->and(DB::table('catalog.categories')->where('is_active', false)->count())->toBe(0)
            ->and(catalogDeactivationsState($t['ready'])['category'])->toBe($t['drawers']);
    });

    it('asks again about the products under a sub-category switched off before', function () {
        $t = catalogDeactivationsTree();
        app(DeactivateCategoryHandler::class)->handle(new DeactivateCategory($t['sinks'], 'HIDE'));

        // The owner, 2026-10-04 (amendment 4(g)): their earlier choice may change — left now.
        app(DeactivateCategoryHandler::class)->handle(new DeactivateCategory($t['kitchens'], 'HIDE', products: [$t['archived'] => ['choice' => 'LEAVE']]));

        expect(catalogDeactivationsState($t['archived'])['hidden_by_category'])->toBeFalse()
            ->and(Fx::audits('catalog.product.left', $t['archived']))->toBe(1)
            ->and(catalogDeactivationsState($t['ready'])['hidden_by_category'])->toBeTrue();
    });

    it('brings back with the category what hid with it, but not under a sub-category still off', function () {
        $t = catalogDeactivationsTree();
        app(DeactivateCategoryHandler::class)->handle(new DeactivateCategory($t['sinks'], 'HIDE'));
        app(DeactivateCategoryHandler::class)->handle(new DeactivateCategory($t['kitchens'], 'HIDE'));
        Event::fake([ProductChanged::class]);

        app(ActivateCategoryHandler::class)->handle(new ActivateCategory($t['kitchens']));

        expect(catalogDeactivationsState($t['ready'])['hidden_by_category'])->toBeFalse()
            ->and(catalogDeactivationsState($t['draft'])['hidden_by_category'])->toBeFalse()
            ->and(catalogDeactivationsState($t['archived'])['hidden_by_category'])->toBeTrue()
            ->and(Fx::audits('catalog.product.shown', $t['ready']))->toBe(1);
        // The draft is Catalog's alone (amendment 3(m)): only the ready drawer is sent.
        Event::assertDispatchedTimes(ProductChanged::class, 1);
        Event::assertDispatched(ProductChanged::class, fn (ProductChanged $event): bool => $event->productId === $t['ready']);
    });

    it('lets a product hidden with its category come back by moving it', function () {
        $t = catalogDeactivationsTree();
        app(DeactivateCategoryHandler::class)->handle(new DeactivateCategory($t['kitchens'], 'HIDE'));
        $product = app(ProductRepository::class)->find($t['draft']) ?? throw new LogicException('No such product.');

        Fx::asSystem(fn () => app(EditProductDetailsHandler::class)->handle(new EditProductDetails($t['draft'], $product->name()->ar, $product->name()->en, $product->brandId(), categoryId: $t['tables'])));

        expect(catalogDeactivationsState($t['draft']))->toMatchArray(['category' => $t['tables'], 'hidden_by_category' => false]);
    });
});

describe('a brand deactivated', function () {
    it('hides or moves each of its products, in any stage, never leaving one without an active brand', function () {
        [$brand, $other] = [Px::brand('Blum'), Px::brand('Hettich')];
        $category = Px::category();
        $hidden = catalogDeactivationsDraft($category, $brand);
        $moved = catalogDeactivationsDraft($category, $brand);

        expect(fn () => app(DeactivateBrandHandler::class)->handle(new DeactivateBrand($brand, 'LEAVE')))->toThrow(InvalidCatalogAttribute::class, 'choice');

        app(DeactivateBrandHandler::class)->handle(new DeactivateBrand($brand, 'HIDE', products: [$moved => ['choice' => 'MOVE', 'move_to' => $other]]));

        expect(catalogDeactivationsState($hidden))->toMatchArray(['brand' => $brand, 'hidden_by_brand' => true])
            ->and(catalogDeactivationsState($moved))->toMatchArray(['brand' => $other, 'hidden_by_brand' => false]);

        app(ActivateBrandHandler::class)->handle(new ActivateBrand($brand));

        expect(catalogDeactivationsState($hidden)['hidden_by_brand'])->toBeFalse()
            ->and(Fx::audits('catalog.product.shown', $hidden))->toBe(1);
    });

    it('moves only to an active brand, and never deactivates the default', function () {
        [$brand, $off] = [Px::brand('Blum'), Px::brand('Grass')];
        $product = catalogDeactivationsDraft(Px::category(), $brand);
        app(DeactivateBrandHandler::class)->handle(new DeactivateBrand($off));
        $default = app(BrandRepository::class)->defaultBrand()?->id() ?? throw new LogicException('No default brand.');

        expect(fn () => app(DeactivateBrandHandler::class)->handle(new DeactivateBrand($brand, 'MOVE', $off)))->toThrow(BrandInactive::class)
            ->and(fn () => app(DeactivateBrandHandler::class)->handle(new DeactivateBrand($default, 'HIDE')))->toThrow(DefaultBrandRequired::class)
            ->and(DB::table('catalog.brands')->where('id', $brand)->value('is_active'))->toBeTrue()
            ->and(catalogDeactivationsState($product)['brand'])->toBe($brand);
    });
});

describe('a product hidden with its brand', function () {
    it('comes back when it moves to another brand', function () {
        [$brand, $other] = [Px::brand('Blum'), Px::brand('Hettich')];
        $id = catalogDeactivationsDraft(Px::category(), $brand);
        app(DeactivateBrandHandler::class)->handle(new DeactivateBrand($brand, 'HIDE'));
        $product = app(ProductRepository::class)->find($id) ?? throw new LogicException('No such product.');

        Fx::asSystem(fn () => app(EditProductDetailsHandler::class)->handle(new EditProductDetails($id, $product->name()->ar, $product->name()->en, $other, categoryId: $product->categoryId())));

        expect(catalogDeactivationsState($id))->toMatchArray(['brand' => $other, 'hidden_by_brand' => false]);
    });
});

describe('what a deactivation keeps', function () {
    /**
     * @return array<string, mixed>
     */
    function catalogDeactivationsAudit(string $action, string $subjectId): array
    {
        return (array) json_decode((string) DB::table('platform.audit_entries')->where('action', $action)->where('subject_id', $subjectId)->orderByDesc('id')->value('changes'), true);
    }

    it('records each product\'s change by value, and tells the modules above of a ready one', function () {
        $t = catalogDeactivationsTree();
        Event::fake([ProductChanged::class]);

        app(DeactivateCategoryHandler::class)->handle(new DeactivateCategory($t['kitchens'], 'HIDE', products: [$t['draft'] => ['choice' => 'MOVE', 'move_to' => $t['tables']]]));

        expect(catalogDeactivationsAudit('catalog.product.hidden', $t['ready']))->toBe(['hidden_by_category' => [false, true]])
            ->and(catalogDeactivationsAudit('catalog.product.moved', $t['draft']))->toBe(['category_id' => [$t['drawers'], $t['tables']]]);
        // The ready drawer and the sink archived after being ready are known outside Catalog; the draft is not.
        Event::assertDispatchedTimes(ProductChanged::class, 2);
        Event::assertDispatched(ProductChanged::class, fn (ProductChanged $event): bool => $event->productId === $t['ready']);
        Event::assertDispatched(ProductChanged::class, fn (ProductChanged $event): bool => $event->productId === $t['archived']);
        Event::assertNotDispatched(ProductChanged::class, fn (ProductChanged $event): bool => $event->productId === $t['draft']);
    });

    it('records a brand\'s products hidden and moved, and brings back and tells of a ready one', function () {
        $ready = Px::ready();
        $brand = (string) DB::table('catalog.products')->where('id', $ready['product'])->value('brand_id');
        $other = Px::brand('Hettich');
        $moved = catalogDeactivationsDraft(Px::category(), $brand);
        Event::fake([ProductChanged::class]);

        app(DeactivateBrandHandler::class)->handle(new DeactivateBrand($brand, 'HIDE', products: [$moved => ['choice' => 'MOVE', 'move_to' => $other]]));
        app(ActivateBrandHandler::class)->handle(new ActivateBrand($brand));

        expect(catalogDeactivationsAudit('catalog.product.hidden', $ready['product']))->toBe(['hidden_by_brand' => [false, true]])
            ->and(catalogDeactivationsAudit('catalog.product.moved', $moved))->toBe(['brand_id' => [$brand, $other]])
            ->and(catalogDeactivationsAudit('catalog.product.shown', $ready['product']))->toBe(['hidden_by_brand' => [true, false]]);
        Event::assertDispatchedTimes(ProductChanged::class, 2);
    });

    it('refuses a brand\'s choice for a product it does not reach, and a choice that is not one', function () {
        [$brand, $elsewhere] = [Px::brand('Blum'), Px::brand('Grass')];
        catalogDeactivationsDraft(Px::category(), $brand);
        $theirs = catalogDeactivationsDraft(Px::category(), $elsewhere);
        $t = catalogDeactivationsTree();

        expect(fn () => app(DeactivateBrandHandler::class)->handle(new DeactivateBrand($brand, 'HIDE', products: [$theirs => ['choice' => 'HIDE']])))->toThrow(InvalidCatalogAttribute::class, 'products')
            ->and(fn () => app(DeactivateCategoryHandler::class)->handle(new DeactivateCategory($t['kitchens'], products: [$t['ready'] => 'HIDE'])))->toThrow(InvalidCatalogAttribute::class, 'products')
            ->and(DB::table('catalog.brands')->where('id', $brand)->value('is_active'))->toBeTrue();
    });
});
