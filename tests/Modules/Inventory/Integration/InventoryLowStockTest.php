<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Application\Command\ArchiveProduct\ArchiveProduct;
use Modules\Catalog\Application\Command\ArchiveProduct\ArchiveProductHandler;
use Modules\Catalog\Application\Command\ArchiveVariant\ArchiveVariant;
use Modules\Catalog\Application\Command\ArchiveVariant\ArchiveVariantHandler;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Inventory\Application\InventoryPermissions;
use Modules\Inventory\Application\Query\LowStock\LowStock;
use Modules\Inventory\Application\Query\LowStock\LowStockHandler;
use Modules\Inventory\Application\Query\LowStock\LowStockList;
use Modules\Inventory\Application\Query\LowStock\LowStockRow;
use Modules\Inventory\Application\Stock\LowStockCount;
use Modules\Inventory\Presentation\Home\LowStockCard;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStore;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStoreHandler;
use Modules\Platform\Public\Dto\HomeFigure;
use Modules\Platform\Public\Dto\HomeScope;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogFixtures as Cx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Inventory\Support\InventoryFixtures as Ix;

use function Pest\Laravel\seed;

/*
|--------------------------------------------------------------------------
| Low stock (inventory.md §1.7; step 4b): the list - every size switched on or with a row, at or below
| its threshold, the fewest left first - its count and its Home card from the same reader. (The store's
| file sets no stock since Catalog's amendment 43: a store's stock comes from Odoo.)
|--------------------------------------------------------------------------
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

/**
 * @param  list<string>  $sizes
 * @return array{product: string, variants: list<string>}
 */
function lowStockReady(array $sizes = ['60 cm', '80 cm', '100 cm']): array
{
    $ready = Px::ready($sizes);
    Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Ix::storeId('sa'), $ready['product'], true)));

    return ['product' => $ready['product'], 'variants' => $ready['variants']];
}

/**
 * @return list<string> the list's sizes, in its order
 */
function lowStockList(): array
{
    return array_map(static fn (LowStockRow $row): string => $row->size->variantId, Fx::asSystem(fn (): LowStockList => app(LowStockHandler::class)->handle(new LowStock(Ix::storeId('sa'))))->rows);
}

describe('the Low Stock list (§1.7)', function () {
    it('lists every size at or below its threshold, the fewest left first, a size with no stock as 0', function () {
        [$few, $plenty, $none] = lowStockReady()['variants'];
        Ix::count('sa', [$few => 3, $plenty => 50]);

        $rows = Fx::asSystem(fn (): LowStockList => app(LowStockHandler::class)->handle(new LowStock(Ix::storeId('sa'))))->rows;

        // The store's default threshold, 10: 3 and 0 are low, 50 is not.
        expect(lowStockList())->toBe([$none, $few])
            ->and($rows[1]->figures->available)->toBe(3)
            ->and($rows[1]->figures->threshold)->toBe(10)
            ->and($rows[1]->size->code)->not->toBe('')
            ->and($rows[1]->size->productNameAr)->not->toBe('');

        DB::table('inventory.stock')->where('store_id', Ix::storeId('sa'))->where('variant_id', $plenty)->update(['threshold' => 50]);

        // Its own threshold: at it is low.
        expect(lowStockList())->toBe([$none, $few, $plenty]);
    });

    it('leaves an archived size off, and a size of an archived product (owner, 2026-10-13)', function () {
        $ready = lowStockReady(['60 cm', '80 cm']);
        [$archived, $kept] = $ready['variants'];
        $other = lowStockReady(['60 cm']);
        // Stock rows of their own, so they would stay on the list by them once archived.
        Ix::count('sa', [$archived => 2, $kept => 2, $other['variants'][0] => 2]);

        expect(lowStockList())->toEqualCanonicalizing([$archived, $kept, $other['variants'][0]]);

        Fx::asSystem(fn () => app(ArchiveVariantHandler::class)->handle(new ArchiveVariant($archived)));
        Fx::asSystem(fn () => app(ArchiveProductHandler::class)->handle(new ArchiveProduct($other['product'])));

        expect(lowStockList())->toBe([$kept]);
    });

    it('counts what orders hold: available is in stock less held', function () {
        [$size] = lowStockReady(['60 cm'])['variants'];
        Ix::count('sa', [$size => 15]);

        expect(lowStockList())->toBe([]);

        DB::table('inventory.stock')->where('store_id', Ix::storeId('sa'))->where('variant_id', $size)->update(['held' => 5]);

        expect(lowStockList())->toBe([$size]);
    });

    it('is read under Manage Stock in that store alone', function () {
        lowStockReady(['60 cm']);
        Cx::actAsStaffWith([InventoryPermissions::STOCK_MANAGE], ['eg']);

        expect(fn () => app(LowStockHandler::class)->handle(new LowStock(Ix::storeId('sa'))))->toThrow(Unauthorized::class);
    });
});

describe('its count and its Home card', function () {
    it('counts the low sizes of the stores the reader holds Manage Stock in', function () {
        [$few] = lowStockReady(['60 cm', '80 cm'])['variants'];
        Ix::count('sa', [$few => 2]);

        Cx::actAsStaffWith([InventoryPermissions::STOCK_MANAGE], ['sa']);

        expect(app(LowStockCount::class)->count())->toBe(2);

        Cx::actAsStaffWith([InventoryPermissions::STOCK_MANAGE], ['eg']);

        expect(app(LowStockCount::class)->count())->toBe(0);
    });

    it('leaves a switched-off store out for a reader who may not switch stores, as the list does', function () {
        $ready = lowStockReady(['60 cm']);
        Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Ix::storeId('eg'), $ready['product'], true)));
        $eg = Ix::storeId('eg');
        $staff = Fx::staffWith([InventoryPermissions::STOCK_MANAGE], ['sa', 'eg']);
        Fx::actAsStaff($staff);

        // Both on: the size, at 0, is low in each.
        expect(app(LowStockCount::class)->count())->toBe(2);

        Fx::asSystem(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore('eg')));
        Fx::actAsStaff($staff);

        expect(app(LowStockCount::class)->count())->toBe(1)
            ->and(app(LowStockCard::class)->data(HomeScope::store(StoreId::fromString($eg))))->toBeNull()
            ->and(Fx::asSystem(fn (): int => app(LowStockCount::class)->count()))->toBe(2);
    });

    it('shows the count and the fewest left on Home, to holders of Manage Stock there only', function () {
        [$few, $none] = lowStockReady(['60 cm', '80 cm'])['variants'];
        Ix::count('sa', [$few => 2]);
        app()->setLocale('en');
        Cx::actAsStaffWith([InventoryPermissions::STOCK_MANAGE], ['sa']);

        $card = app(LowStockCard::class)->data(HomeScope::store(StoreId::fromString(Ix::storeId('sa'))));

        expect($card?->figures)->toHaveCount(1)
            ->and($card?->figures[0])->toBeInstanceOf(HomeFigure::class)
            ->and($card?->figures[0]->value)->toBe(2)
            ->and($card?->figures[0]->tone)->toBe('amber')
            ->and(array_map(static fn ($row): ?string => $row->detail, $card->rows ?? []))->toBe(['0 left', '2 left']);

        Cx::actAsStaffWith([InventoryPermissions::STOCK_MANAGE], ['eg']);

        expect(app(LowStockCard::class)->data(HomeScope::store(StoreId::fromString(Ix::storeId('sa')))))->toBeNull()
            // All Stores: only for a reader holding it everywhere.
            ->and(app(LowStockCard::class)->data(HomeScope::allStores()))->toBeNull()
            ->and(Fx::asSystem(fn () => app(LowStockCard::class)->data(HomeScope::allStores()))?->figures[0]->value)->toBe(2);
    });
});
