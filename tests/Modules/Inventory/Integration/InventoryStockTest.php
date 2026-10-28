<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Catalog\Application\CatalogPermissions;
use Modules\Catalog\Application\Command\ArchiveProduct\ArchiveProduct;
use Modules\Catalog\Application\Command\ArchiveProduct\ArchiveProductHandler;
use Modules\Catalog\Application\Command\ArchiveVariant\ArchiveVariant;
use Modules\Catalog\Application\Command\ArchiveVariant\ArchiveVariantHandler;
use Modules\Inventory\Application\Command\AdjustStock\AdjustStock;
use Modules\Inventory\Application\Command\AdjustStock\AdjustStockHandler;
use Modules\Inventory\Application\Command\SetEndingSoon\SetEndingSoon;
use Modules\Inventory\Application\Command\SetEndingSoon\SetEndingSoonHandler;
use Modules\Inventory\Application\Command\SetLowStockThreshold\SetLowStockThreshold;
use Modules\Inventory\Application\Command\SetLowStockThreshold\SetLowStockThresholdHandler;
use Modules\Inventory\Application\Command\SetStock\SetStock;
use Modules\Inventory\Application\Command\SetStock\SetStockHandler;
use Modules\Inventory\Application\Command\SetStockDependent\SetStockDependent;
use Modules\Inventory\Application\Command\SetStockDependent\SetStockDependentHandler;
use Modules\Inventory\Application\InventoryPermissions;
use Modules\Inventory\Domain\Exception\NoteRequired;
use Modules\Inventory\Domain\Exception\NotWired;
use Modules\Inventory\Domain\Exception\ProviderOwnsStock;
use Modules\Inventory\Domain\Exception\QuantityInvalid;
use Modules\Inventory\Domain\Exception\StockBelowZero;
use Modules\Inventory\Domain\Exception\StoreOff;
use Modules\Inventory\Domain\Exception\ThresholdInvalid;
use Modules\Inventory\Public\Contracts\InventoryApi;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStore;
use Modules\Platform\Application\Command\DeactivateStore\DeactivateStoreHandler;
use Shared\Application\Actor;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogFixtures as Cx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Inventory\Support\InventoryFixtures as Ix;

use function Pest\Laravel\seed;

/*
| A size's stock by hand (inventory.md §1.2, §1.5, §1.6, §3; §8 #6, #9, #11, #12): a stocktake and
| additions or removals with a reason - each reason one way, a correction with its note - in a store
| with no provider; thresholds anywhere; "last pieces" per product with no provider; the
| stock-dependent switch only where wired. Every change of in stock in the ledger, written once; every
| change audited from and to; an archived size read-only until restored; an off store's only for
| whoever may switch stores.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

/**
 * @param  array<string, int>  $counts
 */
function inventoryStockCount(string $storeId, array $counts): void
{
    app(SetStockHandler::class)->handle(new SetStock($storeId, $counts));
}

/**
 * @param  array<string, int|null>  $thresholds
 */
function inventoryStockThreshold(string $storeCode, array $thresholds): void
{
    Fx::asSystem(fn () => app(SetLowStockThresholdHandler::class)->handle(new SetLowStockThreshold(Ix::storeId($storeCode), $thresholds)));
}

/**
 * @param  list<string>  $variantIds
 */
function inventoryStockDependent(string $storeCode, array $variantIds, bool $on): void
{
    Fx::asSystem(fn () => app(SetStockDependentHandler::class)->handle(new SetStockDependent(Ix::storeId($storeCode), $variantIds, $on)));
}

function inventoryStockEndingSoon(string $storeCode, string $productId, bool $on): void
{
    Fx::asSystem(fn () => app(SetEndingSoonHandler::class)->handle(new SetEndingSoon(Ix::storeId($storeCode), $productId, $on)));
}

describe('a stocktake', function () {
    it('sets each size\'s in stock to the number counted, in that store only, by whoever counted', function () {
        $sizes = Px::ready(['60 cm', '80 cm'])['variants'];
        $staff = Cx::actAsStaffWith([InventoryPermissions::STOCK_MANAGE], ['sa']);

        inventoryStockCount(Ix::storeId('sa'), [$sizes[0] => 12, $sizes[1] => 0]);

        expect(Ix::stock('sa', $sizes[0]))->toMatchObject(['in_stock' => 12, 'held' => 0, 'threshold' => null, 'stock_dependent' => true])
            ->and(Ix::stock('eg', $sizes[0]))->toBeNull()
            ->and(Ix::ledger('sa', $sizes[0]))->toBe(['STOCKTAKE 0→12 STAFF'])
            ->and(DB::table('inventory.stock_movements')->where('variant_id', $sizes[0])->value('actor_id'))->toBe($staff)
            ->and(Ix::auditChanges('inventory.stock.counted', $sizes[0]))->toBe(['in_stock' => [0, 12]])
            // A count of 0 on a size with no row yet finds it right: a ledger line, no audit.
            ->and(Ix::ledger('sa', $sizes[1]))->toBe(['STOCKTAKE 0→0 STAFF'])
            ->and(Fx::audits('inventory.stock.counted', $sizes[1]))->toBe(0);
    });

    it('records a count that finds the number right, so the history shows it was counted, and audits nothing (owner, 2026-10-11)', function () {
        $size = Px::ready()['variants'][0];
        Ix::count('sa', [$size => 7]);

        Ix::count('sa', [$size => 7]);

        expect(Ix::ledger('sa', $size))->toBe(['STOCKTAKE 0→7 SYSTEM', 'STOCKTAKE 7→7 SYSTEM'])
            ->and(Fx::audits('inventory.stock.counted', $size))->toBe(1);
    });

    it('refuses a count below 0 or beyond what the column holds, and one size counted twice with two numbers', function () {
        $size = Px::ready()['variants'][0];

        expect(fn () => Ix::count('sa', [$size => -1]))->toThrow(QuantityInvalid::class)
            ->and(fn () => Ix::count('sa', [$size => 2147483648]))->toThrow(QuantityInvalid::class)
            ->and(Ix::invalid(fn () => Ix::count('sa', [$size => 3, strtoupper($size) => 4]))?->attribute)->toBe('variant')
            ->and(Ix::stock('sa', $size))->toBeNull();

        Ix::count('sa', [$size => 3, strtoupper($size) => 3]);

        expect(Ix::stock('sa', $size)?->in_stock)->toBe(3)
            ->and(Ix::ledger('sa', $size))->toBe(['STOCKTAKE 0→3 SYSTEM']);
    });

    it('records whoever queued the job when the system makes the change, as the audit log does', function () {
        $size = Px::ready()['variants'][0];
        $staff = Fx::staff();
        // The actor a queued job acts as. A count that finds the number right writes the ledger
        // alone: the audit log takes such an actor only inside a real job.
        Fx::actAs(Actor::system(Actor::staff($staff)));

        inventoryStockCount(Ix::storeId('sa'), [$size => 0]);

        $line = DB::table('inventory.stock_movements')->where('variant_id', $size)->sole();

        expect([$line->actor_type, $line->actor_id, $line->requested_by_type, $line->requested_by_id])->toBe(['SYSTEM', null, 'STAFF', $staff]);
    });

    it('changes nothing when given nothing', function () {
        Cx::actAsStaffWith([InventoryPermissions::STOCK_MANAGE], ['sa']);
        $queries = Cx::recordQueries();

        inventoryStockCount(Ix::storeId('sa'), []);
        DB::select('SELECT 1');

        expect(array_filter($queries->getArrayCopy(), static fn (array $query): bool => $query['level'] > 1 || str_contains($query['sql'], '"inventory"')))->toBe([])
            ->and($queries->count())->toBeGreaterThan(0);
    });
});

describe('adding and removing by hand', function () {
    it('adds pieces received and removes pieces damaged, lost or sold offline, each in the ledger and audited', function () {
        $size = Px::ready()['variants'][0];

        Ix::adjust('sa', $size, 'ADD', 10, 'RECEIVED');
        Ix::adjust('sa', $size, 'REMOVE', 2, 'DAMAGED_LOST');
        Ix::adjust('sa', $size, 'REMOVE', 3, 'OFFLINE_SALE', '  Sold at the showroom  ');

        expect(Ix::stock('sa', $size)?->in_stock)->toBe(5)
            ->and(Ix::ledger('sa', $size))->toBe([
                'RECEIVED 0→10 SYSTEM',
                'DAMAGED_LOST 10→8 SYSTEM',
                'OFFLINE_SALE 8→5 Sold at the showroom SYSTEM',
            ])
            // The note stays in the ledger: free text is kept out of the audit log. (jsonb keeps
            // its own key order, so the two are compared as a map.)
            ->and(Ix::auditChanges('inventory.stock.adjusted', $size))->toEqual(['in_stock' => [8, 5], 'reason' => [null, 'OFFLINE_SALE']])
            ->and(Fx::audits('inventory.stock.adjusted', $size))->toBe(3);
    });

    it('takes a correction either way, with its note', function () {
        $size = Px::ready()['variants'][0];

        Ix::adjust('sa', $size, 'ADD', 4, 'CORRECTION', 'Miscounted last week');
        Ix::adjust('sa', $size, 'REMOVE', 1, 'CORRECTION', 'One was a display piece');

        expect(Ix::stock('sa', $size)?->in_stock)->toBe(3)
            ->and(Ix::ledger('sa', $size))->toBe(['CORRECTION 0→4 Miscounted last week SYSTEM', 'CORRECTION 4→3 One was a display piece SYSTEM']);
    });

    it('refuses a correction with no note, or a blank one', function (?string $note) {
        $size = Px::ready()['variants'][0];

        expect(fn () => Ix::adjust('sa', $size, 'ADD', 1, 'CORRECTION', $note))->toThrow(NoteRequired::class)
            ->and(Ix::stock('sa', $size))->toBeNull();
    })->with([null, '', '   ']);

    it('takes each reason one way only (owner, 2026-10-11), naming the reason', function (string $direction, string $reason) {
        $size = Px::ready()['variants'][0];
        Ix::count('sa', [$size => 10]);

        expect(Ix::invalid(fn () => Ix::adjust('sa', $size, $direction, 1, $reason, 'a note'))?->attribute)->toBe('reason')
            ->and(Ix::stock('sa', $size)?->in_stock)->toBe(10);
    })->with([
        'received is never removed' => ['REMOVE', 'RECEIVED'],
        'damaged is never added' => ['ADD', 'DAMAGED_LOST'],
        'an offline sale is never added' => ['ADD', 'OFFLINE_SALE'],
        'a stocktake is not a hand reason' => ['ADD', 'STOCKTAKE'],
        'nor is a shipment' => ['REMOVE', 'SHIPPED'],
        'nor anything else' => ['ADD', 'GIFT'],
    ]);

    it('refuses a direction that is not one, a quantity below 1 and a note too long', function () {
        $size = Px::ready()['variants'][0];

        expect(Ix::invalid(fn () => Ix::adjust('sa', $size, 'UP', 1, 'RECEIVED'))?->attribute)->toBe('direction')
            ->and(fn () => Ix::adjust('sa', $size, 'ADD', 0, 'RECEIVED'))->toThrow(QuantityInvalid::class)
            ->and(Ix::invalid(fn () => Ix::adjust('sa', $size, 'ADD', 1, 'CORRECTION', str_repeat('x', 501)))?->attribute)->toBe('note')
            ->and(Ix::stock('sa', $size))->toBeNull();

        Ix::adjust('sa', $size, 'ADD', 1, 'CORRECTION', str_repeat('x', 500));

        expect(Ix::stock('sa', $size)?->in_stock)->toBe(1);
    });

    it('never takes the stock below 0', function () {
        $size = Px::ready()['variants'][0];
        Ix::count('sa', [$size => 2]);

        expect(fn () => Ix::adjust('sa', $size, 'REMOVE', 3, 'DAMAGED_LOST'))->toThrow(StockBelowZero::class)
            ->and(Ix::stock('sa', $size)?->in_stock)->toBe(2)
            ->and(Ix::ledger('sa', $size))->toBe(['STOCKTAKE 0→2 SYSTEM']);

        Ix::adjust('sa', $size, 'REMOVE', 2, 'DAMAGED_LOST');

        expect(Ix::stock('sa', $size)?->in_stock)->toBe(0)
            ->and(fn () => Ix::adjust('sa', $size, 'ADD', 2147483647, 'RECEIVED'))->not->toThrow(Throwable::class)
            ->and(fn () => Ix::adjust('sa', $size, 'ADD', 1, 'RECEIVED'))->toThrow(QuantityInvalid::class);
    });

    it('takes pieces held for orders out when asked: in stock may fall below held, never below 0 (§1.5)', function () {
        $size = Px::ready()['variants'][0];
        Ix::count('sa', [$size => 5]);
        // Holds come with step 3; what they leave on the row is enough here.
        DB::table('inventory.stock')->where('variant_id', $size)->update(['held' => 4]);

        Ix::adjust('sa', $size, 'REMOVE', 3, 'DAMAGED_LOST');

        expect(Ix::stock('sa', $size))->toMatchObject(['in_stock' => 2, 'held' => 4]);
    });

    it('measures a note once trimmed, as the ledger keeps it', function () {
        $size = Px::ready()['variants'][0];

        Ix::adjust('sa', $size, 'ADD', 1, 'CORRECTION', str_repeat('x', 500).'   ');

        expect(Ix::ledger('sa', $size))->toBe(['CORRECTION 0→1 '.str_repeat('x', 500).' SYSTEM'])
            ->and(fn () => Ix::adjust('sa', $size, 'ADD', 1, 'CORRECTION', "\t\n"))->toThrow(NoteRequired::class);
    });
});

describe('the low-stock threshold', function () {
    it('keeps a size\'s own threshold from 0 to 100,000, or the store\'s default when emptied', function () {
        $sizes = Px::ready(['60 cm', '80 cm'])['variants'];

        inventoryStockThreshold('sa', [$sizes[0] => 0, $sizes[1] => 100000]);

        expect(Ix::stock('sa', $sizes[0])?->threshold)->toBe(0)
            ->and(Ix::stock('sa', $sizes[1])?->threshold)->toBe(100000)
            ->and(Ix::stock('sa', $sizes[1])?->in_stock)->toBe(0)
            ->and(Ix::auditChanges('inventory.stock.threshold_set', $sizes[1]))->toBe(['threshold' => [null, 100000]]);

        inventoryStockThreshold('sa', [$sizes[1] => null, $sizes[0] => 0]);

        expect(Ix::stock('sa', $sizes[1])?->threshold)->toBeNull()
            ->and(Ix::auditChanges('inventory.stock.threshold_set', $sizes[1]))->toBe(['threshold' => [100000, null]])
            ->and(Fx::audits('inventory.stock.threshold_set', $sizes[0]))->toBe(1)
            // A threshold is no change of in stock.
            ->and(Ix::ledger('sa', $sizes[0]))->toBe([]);
    });

    it('refuses a threshold below 0 or above 100,000 (owner, 2026-10-10)', function (int $threshold) {
        $size = Px::ready()['variants'][0];

        expect(fn () => inventoryStockThreshold('sa', [$size => $threshold]))->toThrow(ThresholdInvalid::class)
            ->and(Ix::stock('sa', $size))->toBeNull();
    })->with([-1, 100001]);

    it('makes no row for a threshold cleared that was never set, and refuses one size given two thresholds', function () {
        $size = Px::ready()['variants'][0];

        inventoryStockThreshold('sa', [$size => null]);
        inventoryStockThreshold('sa', []);

        expect(Ix::stock('sa', $size))->toBeNull()
            ->and(Fx::audits('inventory.stock.threshold_set', $size))->toBe(0)
            ->and(Ix::invalid(fn () => inventoryStockThreshold('sa', [$size => 3, strtoupper($size) => 4]))?->attribute)->toBe('variant')
            ->and(Ix::stock('sa', $size))->toBeNull();
    });

    it('takes a threshold in a wired store too: it only ever alerts staff', function () {
        $size = Px::ready()['variants'][0];
        Ix::wired();

        inventoryStockThreshold('sa', [$size => 4]);

        expect(Ix::stock('sa', $size)?->threshold)->toBe(4);
    });
});

describe('"last pieces" for a product', function () {
    it('switches it on and off in a store with no provider, audited, the same value changing nothing', function () {
        $product = Px::ready()['product'];

        inventoryStockEndingSoon('sa', $product, true);
        inventoryStockEndingSoon('sa', $product, true);

        expect(DB::table('inventory.store_products')->where('store_id', Ix::storeId('sa'))->where('product_id', $product)->value('ending_soon'))->toBeTrue()
            ->and(Fx::audits('inventory.store_product.ending_soon_set', $product))->toBe(1)
            ->and(Ix::auditChanges('inventory.store_product.ending_soon_set', $product))->toBe(['ending_soon' => [false, true]]);

        inventoryStockEndingSoon('sa', $product, false);

        expect(Ix::auditChanges('inventory.store_product.ending_soon_set', $product))->toBe(['ending_soon' => [true, false]])
            ->and(DB::table('inventory.store_products')->where('product_id', $product)->value('ending_soon'))->toBeFalse();
    });

    it('refuses it in a wired store, and for a product that does not exist or is archived', function () {
        $product = Px::ready()['product'];
        Fx::asSystem(fn () => app(ArchiveProductHandler::class)->handle(new ArchiveProduct($product)));

        expect(Ix::invalid(fn () => inventoryStockEndingSoon('sa', $product, true))?->attribute)->toBe('product')
            ->and(Ix::invalid(fn () => inventoryStockEndingSoon('sa', strtolower((string) Str::ulid()), true))?->attribute)->toBe('product');

        Ix::wired();

        expect(fn () => inventoryStockEndingSoon('sa', Px::ready()['product'], true))->toThrow(ProviderOwnsStock::class);
    });

    it('is Manage Stock\'s in that store, as every other change', function () {
        $product = Px::ready()['product'];
        Cx::actAsStaffWith([InventoryPermissions::STOCK_MANAGE], ['eg']);

        expect(fn () => app(SetEndingSoonHandler::class)->handle(new SetEndingSoon(Ix::storeId('sa'), $product, true)))->toThrow(Unauthorized::class);

        app(SetEndingSoonHandler::class)->handle(new SetEndingSoon(Ix::storeId('eg'), $product, true));

        expect(DB::table('inventory.store_products')->where('store_id', Ix::storeId('eg'))->where('product_id', $product)->value('ending_soon'))->toBeTrue()
            ->and(DB::table('inventory.store_products')->where('store_id', Ix::storeId('sa'))->exists())->toBeFalse();
    });
});

describe('the stock-dependent switch', function () {
    it('is refused in a store with no provider', function () {
        $size = Px::ready()['variants'][0];

        expect(fn () => inventoryStockDependent('sa', [$size], true))->toThrow(NotWired::class)
            ->and(Ix::stock('sa', $size))->toBeNull();
    });

    it('switches off the sizes picked in a wired store - some of a product\'s - leaving the others on, and back on', function () {
        $sizes = Px::ready(['60 cm', '80 cm', '100 cm'])['variants'];
        Ix::wired();

        // On by default (§9.2 #24): switching off makes the size's row, kept with the switch off.
        inventoryStockDependent('sa', [$sizes[0], $sizes[1]], false);

        expect(Ix::stock('sa', $sizes[0])?->stock_dependent)->toBeFalse()
            ->and(Ix::stock('sa', $sizes[1])?->stock_dependent)->toBeFalse()
            ->and(Ix::stock('sa', $sizes[2]))->toBeNull()
            ->and(Ix::auditChanges('inventory.stock.stock_dependent_set', $sizes[0]))->toBe(['stock_dependent' => [true, false]]);

        inventoryStockDependent('sa', [$sizes[1], $sizes[2]], true);

        // Back on keeps the row; switching on a size that was never off changes nothing and makes no row.
        expect(Ix::stock('sa', $sizes[1])?->stock_dependent)->toBeTrue()
            ->and(Ix::auditChanges('inventory.stock.stock_dependent_set', $sizes[1]))->toBe(['stock_dependent' => [false, true]])
            ->and(Ix::stock('sa', $sizes[0])?->stock_dependent)->toBeFalse()
            ->and(Fx::audits('inventory.stock.stock_dependent_set', $sizes[2]))->toBe(0)
            ->and(Ix::stock('sa', $sizes[2]))->toBeNull();

        // Off twice changes nothing more.
        inventoryStockDependent('sa', [$sizes[0]], false);

        expect(Fx::audits('inventory.stock.stock_dependent_set', $sizes[0]))->toBe(1);
    });

    it('counts a size added later on stock, though every other size of its product is off', function () {
        $ready = Px::ready(['60 cm', '80 cm']);
        Ix::wired();
        inventoryStockDependent('sa', $ready['variants'], false);

        $later = Px::variant($ready['product'], '7000001', [$ready['width'] => Px::value($ready['width'], '120 cm')]);
        $stock = app(InventoryApi::class)->stock(StoreId::fromString(Ix::storeId('sa')), [$later, $ready['variants'][0]]);

        expect(Ix::stock('sa', $later))->toBeNull()
            ->and($stock[$later]->countsOnStock)->toBeTrue()
            ->and($stock[$ready['variants'][0]]->countsOnStock)->toBeFalse()
            ->and(Ix::stock('sa', $ready['variants'][0])?->stock_dependent)->toBeFalse();
    });

    it('is Manage Stock\'s in that store, as every other change', function () {
        $size = Px::ready()['variants'][0];
        Ix::wired();
        Cx::actAsStaffWith([InventoryPermissions::STOCK_MANAGE], ['eg']);

        expect(fn () => app(SetStockDependentHandler::class)->handle(new SetStockDependent(Ix::storeId('sa'), [$size], false)))->toThrow(Unauthorized::class);

        app(SetStockDependentHandler::class)->handle(new SetStockDependent(Ix::storeId('eg'), [$size], false));

        expect(Ix::stock('eg', $size)?->stock_dependent)->toBeFalse()
            ->and(Ix::stock('sa', $size))->toBeNull();
    });
});

describe('who may change a store\'s stock', function () {
    it('lets Manage Stock in that store, and refuses it in another or without it', function () {
        $size = Px::ready()['variants'][0];
        Cx::actAsStaffWith([InventoryPermissions::STOCK_MANAGE], ['eg']);

        inventoryStockCount(Ix::storeId('eg'), [$size => 5]);

        expect(Ix::stock('eg', $size)?->in_stock)->toBe(5)
            ->and(fn () => inventoryStockCount(Ix::storeId('sa'), [$size => 5]))->toThrow(Unauthorized::class)
            ->and(fn () => app(AdjustStockHandler::class)->handle(new AdjustStock(Ix::storeId('sa'), $size, 'ADD', 1, 'RECEIVED')))->toThrow(Unauthorized::class)
            ->and(fn () => app(SetLowStockThresholdHandler::class)->handle(new SetLowStockThreshold(Ix::storeId('sa'), [$size => 1])))->toThrow(Unauthorized::class);

        Cx::actAsStaffWith([CatalogPermissions::PRODUCT_UPDATE], ['*']);

        expect(fn () => inventoryStockCount(Ix::storeId('eg'), [$size => 6]))->toThrow(Unauthorized::class)
            ->and(Ix::stock('eg', $size)?->in_stock)->toBe(5);
    });

    it('refuses a store id that is not one as the permission missing, and an unknown store as invalid', function () {
        $size = Px::ready()['variants'][0];
        Cx::actAsStaffWith([InventoryPermissions::STOCK_MANAGE], ['*']);

        expect(fn () => inventoryStockCount('not-a-store', [$size => 1]))->toThrow(Unauthorized::class)
            ->and(Ix::invalid(fn () => inventoryStockCount(strtolower((string) Str::ulid()), [$size => 1]))?->attribute)->toBe('store');
    });

    it('refuses an off store\'s stock to whoever may not switch stores, and lets a Super Admin', function () {
        $size = Px::ready()['variants'][0];
        Fx::asSystem(fn () => app(DeactivateStoreHandler::class)->handle(new DeactivateStore('eg')));
        Cx::actAsStaffWith([InventoryPermissions::STOCK_MANAGE], ['*']);

        expect(fn () => inventoryStockCount(Ix::storeId('eg'), [$size => 1]))->toThrow(StoreOff::class)
            ->and(fn () => app(AdjustStockHandler::class)->handle(new AdjustStock(Ix::storeId('eg'), $size, 'ADD', 1, 'RECEIVED')))->toThrow(StoreOff::class);

        Fx::actAsStaff(Fx::staff(superAdmin: true));
        inventoryStockCount(Ix::storeId('eg'), [$size => 1]);

        expect(Ix::stock('eg', $size)?->in_stock)->toBe(1);
    });

    it('refuses hand changes in a wired store: its stock is the provider\'s', function () {
        $size = Px::ready()['variants'][0];
        Ix::count('sa', [$size => 3]);
        Ix::wired();

        expect(fn () => Ix::count('sa', [$size => 1]))->toThrow(ProviderOwnsStock::class)
            ->and(fn () => Ix::adjust('sa', $size, 'ADD', 1, 'RECEIVED'))->toThrow(ProviderOwnsStock::class)
            ->and(Ix::stock('sa', $size)?->in_stock)->toBe(3);
    });

    it('keeps an archived size, or a size of an archived product, as it is until restored (owner, 2026-10-11)', function () {
        $ready = Px::ready(['60 cm', '80 cm']);
        Ix::count('sa', [$ready['variants'][1] => 4]);
        Fx::asSystem(fn () => app(ArchiveVariantHandler::class)->handle(new ArchiveVariant($ready['variants'][1])));
        $retired = Px::ready();
        Fx::asSystem(fn () => app(ArchiveProductHandler::class)->handle(new ArchiveProduct($retired['product'])));

        foreach ([$ready['variants'][1], $retired['variants'][0], strtolower((string) Str::ulid()), 'not-an-id', '123'] as $size) {
            expect(Ix::invalid(fn () => Ix::count('sa', [$ready['variants'][0] => 1, $size => 1]))?->attribute)->toBe('variant', "{$size} was counted")
                ->and(Ix::invalid(fn () => Ix::adjust('sa', $size, 'ADD', 1, 'RECEIVED'))?->attribute)->toBe('variant')
                ->and(Ix::invalid(fn () => inventoryStockThreshold('sa', [$size => 1]))?->attribute)->toBe('variant');
        }

        Ix::wired();

        expect(Ix::invalid(fn () => inventoryStockDependent('sa', [$ready['variants'][1]], true))?->attribute)->toBe('variant')
            ->and(Ix::stock('sa', $ready['variants'][1])?->in_stock)->toBe(4)
            ->and(Ix::stock('sa', $ready['variants'][0]))->toBeNull();
    });
});

describe('one change, one transaction', function () {
    it('takes the store\'s lock first, then locks the sizes\' rows, inside the change\'s own transaction', function () {
        $size = Px::ready()['variants'][0];
        $queries = Cx::recordQueries();

        Ix::count('sa', [$size => 5]);

        $inside = array_values(array_filter($queries->getArrayCopy(), static fn (array $query): bool => $query['level'] === 2));
        $locked = array_values(array_filter($inside, static fn (array $query): bool => str_contains($query['sql'], '"inventory"."stock"') && str_contains($query['sql'], 'for update')));

        // Rows locked by size id, the one order every writer keeps (StockRows), so none waits on another.
        expect($inside[0]['sql'] ?? null)->toContain('pg_advisory_xact_lock')
            ->and($inside[0]['bindings'] ?? null)->toBe(['inventory:store:'.Ix::storeId('sa')])
            // The change's own read, and Catalog's facts read again under the same lock (step 4).
            ->and($locked)->toHaveCount(2)
            ->and(array_filter($locked, static fn (array $query): bool => ! str_contains($query['sql'], 'order by "variant_id" asc')))->toBe([]);
    });

    it('writes nothing - no stock, no ledger line - when the change is rolled back', function () {
        $size = Px::ready()['variants'][0];
        Ix::count('sa', [$size => 5]);
        // The audit entry is written last: refused, it rolls the whole change back.
        DB::statement('ALTER TABLE platform.audit_entries ADD CONSTRAINT inventory_test_refused CHECK (false) NOT VALID');

        expect(fn () => Ix::adjust('sa', $size, 'ADD', 1, 'RECEIVED'))->toThrow(QueryException::class)
            ->and(Ix::stock('sa', $size)?->in_stock)->toBe(5)
            ->and(Ix::ledger('sa', $size))->toBe(['STOCKTAKE 0→5 SYSTEM']);
    });
});

describe('the ledger (§8 #11)', function () {
    it('is written once: an update, a delete or a truncate is refused by the database', function () {
        $size = Px::ready()['variants'][0];
        Ix::count('sa', [$size => 5]);

        expect(fn () => DB::transaction(fn () => DB::table('inventory.stock_movements')->update(['note' => 'changed'])))->toThrow(QueryException::class, 'append-only')
            ->and(fn () => DB::transaction(fn () => DB::table('inventory.stock_movements')->delete()))->toThrow(QueryException::class, 'append-only')
            ->and(fn () => DB::transaction(fn () => DB::statement('TRUNCATE inventory.stock_movements')))->toThrow(QueryException::class, 'append-only')
            ->and(Ix::ledger('sa', $size))->toBe(['STOCKTAKE 0→5 SYSTEM']);
    });

    it('counts a reference once', function () {
        $size = Px::ready()['variants'][0];
        $row = [
            'store_id' => Ix::storeId('sa'), 'variant_id' => $size, 'reason' => 'SHIPPED', 'change' => -1, 'before' => 1,
            'after' => 0, 'note' => null, 'reference' => 'ship:order-1:line-1', 'actor_type' => 'SYSTEM', 'actor_id' => null,
        ];
        DB::table('inventory.stock_movements')->insert($row);

        expect(fn () => DB::transaction(fn () => DB::table('inventory.stock_movements')->insert($row)))->toThrow(QueryException::class, 'stock_movements_reference');
    });
});
