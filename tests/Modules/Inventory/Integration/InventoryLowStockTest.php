<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Modules\Access\Domain\ValueObject\RoleLevel;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Inventory\Application\Command\SetStockFromFile\SetStockFromFile;
use Modules\Inventory\Application\Command\SetStockFromFile\SetStockFromFileHandler;
use Modules\Inventory\Application\InventoryPermissions;
use Modules\Inventory\Application\Query\LowStock\LowStock;
use Modules\Inventory\Application\Query\LowStock\LowStockHandler;
use Modules\Inventory\Application\Query\LowStock\LowStockRow;
use Modules\Inventory\Application\Stock\LowStockCount;
use Modules\Inventory\Infrastructure\Import\StockImportSection;
use Modules\Inventory\Infrastructure\Queue\SetStockFromFileJob;
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
| Low stock and the store's file (inventory.md §1.7, §1.8; step 4b): the list - every size switched on
| or with a row, at or below its threshold, the fewest left first - its count and its Home card from the
| same reader; the file's stock set once Catalog's change commits, as the file's, never inside it.
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
    return array_map(static fn (LowStockRow $row): string => $row->variantId, Fx::asSystem(fn (): array => app(LowStockHandler::class)->handle(new LowStock(Ix::storeId('sa')))));
}

describe('the Low Stock list (§1.7)', function () {
    it('lists every size at or below its threshold, the fewest left first, a size with no stock as 0', function () {
        [$few, $plenty, $none] = lowStockReady()['variants'];
        Ix::count('sa', [$few => 3, $plenty => 50]);

        $rows = Fx::asSystem(fn (): array => app(LowStockHandler::class)->handle(new LowStock(Ix::storeId('sa'))));

        // The store's default threshold, 10: 3 and 0 are low, 50 is not.
        expect(lowStockList())->toBe([$none, $few])
            ->and($rows[1]->available)->toBe(3)
            ->and($rows[1]->threshold)->toBe(10)
            ->and($rows[1]->code)->not->toBe('')
            ->and($rows[1]->productNameAr)->not->toBe('');

        DB::table('inventory.stock')->where('store_id', Ix::storeId('sa'))->where('variant_id', $plenty)->update(['threshold' => 50]);

        // Its own threshold: at it is low.
        expect(lowStockList())->toBe([$none, $few, $plenty]);
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

describe('the store\'s file (§1.8)', function () {
    it('says the stock each item will set, or why not', function () {
        app()->setLocale('en');
        $section = app(StockImportSection::class);
        $store = StoreId::fromString(Ix::storeId('sa'));

        expect($section->lines($store, '10', null))->toBe([])
            ->and($section->lines($store, '10', 7))->toBe(['The stock will be set to 7.'])
            ->and($section->lines($store, '10', -1)[0] ?? '')->toStartWith('The stock -1 cannot be kept');

        Ix::wired();

        expect(app(StockImportSection::class)->lines($store, '10', 7))->toBe(['The stock is ignored: this store takes its stock from its provider.']);
    });

    it('sets the stock once Catalog\'s change commits, as the file\'s, for whoever switched it on', function () {
        [$size] = lowStockReady(['60 cm'])['variants'];
        Ix::count('sa', [$size => 4]);
        // Whoever switches a file's items on: catalog.listing.fill is admin-only.
        Fx::actAsStaff(Fx::staff(superAdmin: true));
        $insideTheChange = null;

        DB::transaction(function () use ($size, &$insideTheChange): void {
            app(StockImportSection::class)->accepted(StoreId::fromString(Ix::storeId('sa')), [$size], '10', 9);
            $insideTheChange = (int) Ix::stock('sa', $size)?->in_stock;
        });

        expect($insideTheChange)->toBe(4)
            ->and(Ix::stock('sa', $size)?->in_stock)->toBe(9)
            ->and(Ix::ledger('sa', $size))->toBe(['STOCKTAKE 0→4 SYSTEM', 'FILE 4→9 SYSTEM'])
            ->and(DB::table('inventory.stock_movements')->where('reason', 'FILE')->value('requested_by_type'))->toBe('STAFF')
            ->and(Ix::auditChanges('inventory.stock.filed', $size))->toBe(['in_stock' => [4, 9]]);
    });

    it('leaves a wired store\'s stock, and an item with no stock, alone', function () {
        [$size] = lowStockReady(['60 cm'])['variants'];
        Ix::count('sa', [$size => 4]);
        $section = app(StockImportSection::class);
        $store = StoreId::fromString(Ix::storeId('sa'));

        Fx::asSystem(fn () => $section->accepted($store, [$size], '10', null));
        Ix::wired();
        Fx::asSystem(fn () => app(StockImportSection::class)->accepted($store, [$size], '10', 9));

        expect(Ix::stock('sa', $size)?->in_stock)->toBe(4)
            ->and(DB::table('inventory.stock_movements')->where('reason', 'FILE')->count())->toBe(0);
    });

    it('queues nothing for a wired store, and the job itself leaves one alone', function () {
        [$size] = lowStockReady(['60 cm'])['variants'];
        Ix::count('sa', [$size => 4]);
        Ix::wired();
        Queue::fake();

        Fx::asSystem(fn () => app(StockImportSection::class)->accepted(StoreId::fromString(Ix::storeId('sa')), [$size], '10', 9));
        Queue::assertNothingPushed();

        Fx::asSystem(fn () => app(SetStockFromFileHandler::class)->handle(new SetStockFromFile(Ix::storeId('sa'), $size, 9, 'file:wired')));

        expect(Ix::stock('sa', $size)?->in_stock)->toBe(4);
    });

    it('records a file that finds the number right, and audits nothing for it', function () {
        [$size] = lowStockReady(['60 cm'])['variants'];
        Ix::count('sa', [$size => 4]);

        Fx::asSystem(fn () => app(SetStockFromFileHandler::class)->handle(new SetStockFromFile(Ix::storeId('sa'), $size, 4, 'file:same')));

        expect(Ix::ledger('sa', $size))->toBe(['STOCKTAKE 0→4 SYSTEM', 'FILE 4→4 SYSTEM'])
            ->and(Ix::lastAudit('inventory.stock.filed', $size))->toBeNull();
    });

    it('sets nothing twice when the job runs again, though stock changed between', function () {
        [$size] = lowStockReady(['60 cm'])['variants'];
        $file = fn () => Fx::asSystem(fn () => app(SetStockFromFileHandler::class)->handle(new SetStockFromFile(Ix::storeId('sa'), $size, 9, 'file:once')));

        $file();
        Ix::count('sa', [$size => 5]);
        $file();

        expect(Ix::stock('sa', $size)?->in_stock)->toBe(5)
            ->and(Ix::ledger('sa', $size))->toBe(['FILE 0→9 SYSTEM', 'STOCKTAKE 9→5 SYSTEM']);
    });

    it('sets an off store\'s stock for the admin who filled it, though they may not switch stores', function () {
        $ready = lowStockReady(['60 cm']);
        [$size] = $ready['variants'];
        Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Ix::storeId('eg'), $ready['product'], true)));
        // Read while it is on: the fixtures look up stores that are.
        $eg = Ix::storeId('eg');
        $admin = Fx::staffWith([CatalogPermissions::LISTING_FILL], ['eg'], RoleLevel::Admin);
        Fx::asSystem(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore('eg')));

        // Queued by the admin, run by the queue as the system on their behalf.
        Fx::actAsStaff($admin);
        SetStockFromFileJob::dispatch($eg, $size, 7, 'file:off');

        expect((int) DB::table('inventory.stock')->where('store_id', $eg)->where('variant_id', $size)->value('in_stock'))->toBe(7);
    });
});
