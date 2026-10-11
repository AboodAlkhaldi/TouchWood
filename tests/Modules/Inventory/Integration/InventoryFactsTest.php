<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Inventory\Application\Command\RebuildOrderable\RebuildOrderable;
use Modules\Inventory\Application\Command\RebuildOrderable\RebuildOrderableHandler;
use Modules\Inventory\Application\Command\SetEndingSoon\SetEndingSoon;
use Modules\Inventory\Application\Command\SetEndingSoon\SetEndingSoonHandler;
use Modules\Inventory\Application\Command\SetLowStockThreshold\SetLowStockThreshold;
use Modules\Inventory\Application\Command\SetLowStockThreshold\SetLowStockThresholdHandler;
use Modules\Inventory\Application\Command\SetStockDependent\SetStockDependent;
use Modules\Inventory\Application\Command\SetStockDependent\SetStockDependentHandler;
use Modules\Inventory\Application\InventoryPermissions;
use Modules\Inventory\Application\InventorySettings;
use Modules\Inventory\Presentation\Console\RebuildOrderableCommand;
use Modules\Inventory\Public\Contracts\InventoryApi;
use Modules\Inventory\Public\Dto\HoldLineDto;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSetting;
use Modules\Platform\Application\Command\UpdateSetting\UpdateSettingHandler;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogFixtures as Cx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Inventory\Support\InventoryFixtures as Ix;

use function Pest\Laravel\artisan;
use function Pest\Laravel\seed;

/*
| What Catalog is told (inventory.md §1.2, §1.3, §2.3; §8 #5, #8): whether a size can be ordered and
| whether it is "ending soon", pushed inside each change's own transaction and only when it flips; a
| rolled-back change tells nothing; sizes switched on told at once, as 0 with no stock; the default
| threshold's change told; the repair telling everything again.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

/**
 * @return array{bool|null, bool}|null [orderable, ending soon] as Catalog keeps them in KSA, or null
 */
function inventoryFactsTold(string $variantId): ?array
{
    $row = DB::table('catalog.store_variant_facts')->where('store_id', Ix::storeId('sa'))->where('variant_id', $variantId)->first();

    return $row === null ? null : [$row->orderable === null ? null : (bool) $row->orderable, (bool) $row->ending_soon];
}

function inventoryFactsThreshold(string $variantId, ?int $threshold): void
{
    Fx::asSystem(fn () => app(SetLowStockThresholdHandler::class)->handle(new SetLowStockThreshold(Ix::storeId('sa'), [$variantId => $threshold])));
}

it('tells Catalog a size can be ordered when it has stock, and cannot when it has none or all is held', function () {
    $size = Px::ready()['variants'][0];

    Ix::count('sa', [$size => 0]);
    expect(inventoryFactsTold($size))->toBe([false, false]);

    Ix::count('sa', [$size => 1]);
    expect(inventoryFactsTold($size))->toBe([true, false]);

    $order = strtolower((string) Str::ulid());
    app(InventoryApi::class)->hold(StoreId::fromString(Ix::storeId('sa')), $order, [new HoldLineDto($size, 1)], null);
    expect(inventoryFactsTold($size))->toBe([false, false]);

    app(InventoryApi::class)->release($order);
    expect(inventoryFactsTold($size))->toBe([true, false]);
});

it('tells a flip from a removal by hand and from a shipment', function () {
    [$a, $b] = Px::ready(['60 cm', '80 cm'])['variants'];
    Ix::count('sa', [$a => 1, $b => 1]);

    Ix::adjust('sa', $a, 'REMOVE', 1, 'DAMAGED_LOST');

    $order = strtolower((string) Str::ulid());
    app(InventoryApi::class)->hold(StoreId::fromString(Ix::storeId('sa')), $order, [new HoldLineDto($b, 1)], null);
    app(InventoryApi::class)->ship($order, [new HoldLineDto($b, 1)]);

    expect(inventoryFactsTold($a))->toBe([false, false])
        ->and(inventoryFactsTold($b))->toBe([false, false])
        ->and(Ix::stock('sa', $b))->toMatchObject(['in_stock' => 0, 'held' => 0]);
});

it('tells only a flip, inside the change\'s own transaction, and nothing when the change is rolled back', function () {
    $size = Px::ready()['variants'][0];
    Ix::count('sa', [$size => 5]);
    $queries = Cx::recordQueries();

    Ix::adjust('sa', $size, 'ADD', 1, 'RECEIVED');

    expect(array_filter($queries->getArrayCopy(), static fn (array $query): bool => str_contains($query['sql'], 'store_variant_facts')))->toBe([]);

    DB::statement('ALTER TABLE platform.audit_entries ADD CONSTRAINT inventory_facts_test_refused CHECK (false) NOT VALID');

    expect(fn () => Ix::adjust('sa', $size, 'REMOVE', 6, 'DAMAGED_LOST'))->toThrow(QueryException::class)
        ->and(inventoryFactsTold($size))->toBe([true, false]);
});

it('tells "last pieces" with no provider only once staff switch it on for the product, by the threshold', function () {
    $ready = Px::ready(['60 cm', '80 cm']);
    [$a, $b] = $ready['variants'];
    Ix::count('sa', [$a => 3, $b => 30]);

    expect(inventoryFactsTold($a))->toBe([true, false]);

    Fx::asSystem(fn () => app(SetEndingSoonHandler::class)->handle(new SetEndingSoon(Ix::storeId('sa'), $ready['product'], true)));

    expect(inventoryFactsTold($a))->toBe([true, true])
        ->and(inventoryFactsTold($b))->toBe([true, false]);

    // At the threshold exactly is "last pieces"; one below it is not.
    inventoryFactsThreshold($a, 3);

    expect(inventoryFactsTold($a))->toBe([true, true]);

    inventoryFactsThreshold($a, 2);
    inventoryFactsThreshold($b, 40);

    expect(inventoryFactsTold($a))->toBe([true, false])
        ->and(inventoryFactsTold($b))->toBe([true, true]);
});

it('tells the store\'s sizes again when its default threshold changes', function () {
    $ready = Px::ready();
    $size = $ready['variants'][0];
    Ix::count('sa', [$size => 15]);
    Fx::asSystem(fn () => app(SetEndingSoonHandler::class)->handle(new SetEndingSoon(Ix::storeId('sa'), $ready['product'], true)));

    expect(inventoryFactsTold($size))->toBe([true, false]);

    Fx::actAsStaff(Fx::staff(superAdmin: true));
    app(UpdateSettingHandler::class)->handle(new UpdateSetting(InventorySettings::LOW_STOCK_DEFAULT, 'sa', 20));

    expect(inventoryFactsTold($size))->toBe([true, true]);
});

it('tells sizes switched on in a store at once, as 0 when they have no stock', function () {
    $ready = Px::ready(['60 cm', '80 cm']);
    Ix::count('sa', [$ready['variants'][0] => 2]);

    Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Ix::storeId('sa'), $ready['product'], true)));

    expect(inventoryFactsTold($ready['variants'][0]))->toBe([true, false])
        ->and(inventoryFactsTold($ready['variants'][1]))->toBe([false, false]);
});

it('in a wired store tells an ordinary product orderable at 0, and a stock-dependent size\'s "last pieces" by itself', function () {
    [$ordinary, $dependent] = Px::ready(['60 cm', '80 cm'])['variants'];
    Ix::wired();
    DB::table('inventory.stock')->insert([
        ['store_id' => Ix::storeId('sa'), 'variant_id' => $ordinary, 'product_id' => DB::table('catalog.variants')->where('id', $ordinary)->value('product_id'), 'in_stock' => 0, 'updated_at' => now()],
        ['store_id' => Ix::storeId('sa'), 'variant_id' => $dependent, 'product_id' => DB::table('catalog.variants')->where('id', $dependent)->value('product_id'), 'in_stock' => 2, 'updated_at' => now()],
    ]);

    Fx::asSystem(fn () => app(SetStockDependentHandler::class)->handle(new SetStockDependent(Ix::storeId('sa'), [$dependent], true)));
    Fx::asSystem(fn () => app(RebuildOrderableHandler::class)->handle(new RebuildOrderable));

    expect(inventoryFactsTold($ordinary))->toBe([true, false])
        ->and(inventoryFactsTold($dependent))->toBe([true, true]);
});

describe('the repair (§8 #5)', function () {
    it('tells every size again, in every store, from the stock', function () {
        $ready = Px::ready(['60 cm', '80 cm']);
        [$a, $b] = $ready['variants'];
        Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Ix::storeId('sa'), $ready['product'], true)));
        Ix::count('sa', [$a => 2]);
        DB::table('catalog.store_variant_facts')->where('store_id', Ix::storeId('sa'))->update(['orderable' => null, 'ending_soon' => true]);

        $pending = artisan(RebuildOrderableCommand::NAME);

        if (! $pending instanceof PendingCommand) {
            throw new LogicException('Console output mocking is off, so the command cannot be checked.');
        }

        $pending->expectsOutputToContain('Sizes told: 2.')->assertSuccessful()->run();

        expect(inventoryFactsTold($a))->toBe([true, false])
            ->and(inventoryFactsTold($b))->toBe([false, false]);
    });

    it('is the system\'s: no role runs it', function () {
        Cx::actAsStaffWith([InventoryPermissions::STOCK_MANAGE]);

        expect(fn () => app(RebuildOrderableHandler::class)->handle(new RebuildOrderable))->toThrow(Unauthorized::class);
    });
});
