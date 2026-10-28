<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Inventory\Application\Command\CountLowStock\CountLowStock;
use Modules\Inventory\Application\Command\CountLowStock\CountLowStockHandler;
use Modules\Inventory\Application\Command\RebuildOrderable\RebuildOrderable;
use Modules\Inventory\Application\Command\RebuildOrderable\RebuildOrderableHandler;
use Modules\Inventory\Application\Command\SetStockDependent\SetStockDependent;
use Modules\Inventory\Application\Command\SetStockDependent\SetStockDependentHandler;
use Modules\Inventory\Application\InventoryPermissions;
use Modules\Inventory\Application\Query\ViewStock\ViewStock;
use Modules\Inventory\Application\Query\ViewStock\ViewStockHandler;
use Modules\Inventory\Application\Stock\LowStockReader;
use Modules\Inventory\Presentation\Console\RebuildOrderableCommand;
use Modules\Inventory\Public\Contracts\InventoryApi;
use Modules\Inventory\Public\Dto\HoldLineDto;
use Shared\Application\Actor;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogFixtures as Cx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Inventory\Support\InventoryFixtures as Ix;

use function Pest\Laravel\artisan;
use function Pest\Laravel\seed;

/*
| The stock-dependent switch on by default (owner, 2026-10-28; inventory.md §9.2 #24): in a wired store
| every size counts on stock - those already there through the migration, and new ones - unless staff
| switch it off. Catalog's facts and the kept Low Stock counts follow once `inventory:orderable:rebuild`
| runs after the migration (handoff §15.4).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

function stockDefaultColumnDefault(): ?string
{
    $default = DB::table('information_schema.columns')
        ->where('table_schema', 'inventory')->where('table_name', 'stock')->where('column_name', 'stock_dependent')
        ->value('column_default');

    return $default === null ? null : (string) $default;
}

function stockDefaultRebuild(): void
{
    Fx::asSystem(fn (): int => app(RebuildOrderableHandler::class)->handle(new RebuildOrderable));
}

function stockDefaultOrderable(string $variantId): ?bool
{
    $orderable = DB::table('catalog.store_variant_facts')->where('store_id', Ix::storeId('sa'))->where('variant_id', $variantId)->value('orderable');

    return $orderable === null ? null : (bool) $orderable;
}

function stockDefaultBadge(string $variantId): ?string
{
    Cx::actAsStaffWith([InventoryPermissions::STOCK_MANAGE], ['sa']);

    return app(ViewStockHandler::class)->handle(new ViewStock(Ix::storeId('sa'), $variantId))->figures->badge;
}

it('makes every existing size stock-dependent, and the column\'s default on; down puts the default back only', function () {
    $migration = require base_path('src/Modules/Inventory/Infrastructure/Persistence/Migrations/2026_10_28_100000_stock_dependent_on_by_default.php');
    // The database as it was before the change: the column off by default.
    $migration->down();
    $ready = Px::ready(['60 cm', '80 cm', '100 cm']);
    [$atZero, $withStock, $never] = $ready['variants'];
    Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Ix::storeId('sa'), $ready['product'], true)));
    Ix::count('sa', [$atZero => 0, $withStock => 30]);
    Ix::wired();
    // What Catalog was told while the rows there were off: orderable whatever the number. (A size with
    // no row is on in the code already, so the one never counted is not.)
    stockDefaultRebuild();

    expect(Ix::stock('sa', $atZero)?->stock_dependent)->toBeFalse()
        ->and(array_map(stockDefaultOrderable(...), [$atZero, $withStock, $never]))->toBe([true, true, false])
        ->and(stockDefaultBadge($atZero))->toBe('ORDERABLE');

    $migration->up();

    expect(Ix::stock('sa', $atZero)?->stock_dependent)->toBeTrue()
        ->and(Ix::stock('sa', $withStock)?->stock_dependent)->toBeTrue()
        ->and(stockDefaultColumnDefault())->toBe('true')
        ->and(app(InventoryApi::class)->stock(StoreId::fromString(Ix::storeId('sa')), [$atZero])[$atZero]->countsOnStock)->toBeTrue()
        // Catalog keeps the old facts until the repair runs: the deploy step.
        ->and(stockDefaultOrderable($atZero))->toBeTrue();

    // Run from the console, as the deploy step does: the system's, not the staff member's who read last.
    Fx::actAs(Actor::system());
    $pending = artisan(RebuildOrderableCommand::NAME);

    if (! $pending instanceof PendingCommand) {
        throw new LogicException('Console output mocking is off, so the command cannot be checked.');
    }

    $pending->expectsOutputToContain('Sizes told: 3.')->assertSuccessful()->run();

    expect(array_map(stockDefaultOrderable(...), [$atZero, $withStock, $never]))->toBe([false, true, false])
        ->and(stockDefaultBadge($atZero))->toBe('OUT_OF_STOCK')
        ->and(stockDefaultBadge($never))->toBe('OUT_OF_STOCK');

    // Down: the default only. The rows stay on - which were off before cannot be told.
    $migration->down();

    expect(stockDefaultColumnDefault())->toBe('false')
        ->and(Ix::stock('sa', $atZero)?->stock_dependent)->toBeTrue();
});

it('keeps the Low Stock count the list\'s, the default making the low sizes Out of Stock rather than Orderable', function () {
    $ready = Px::ready(['60 cm', '80 cm', '100 cm']);
    [$atZero, $withStock, $never] = $ready['variants'];
    Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Ix::storeId('sa'), $ready['product'], true)));
    Ix::count('sa', [$atZero => 0, $withStock => 30]);
    Ix::wired();
    stockDefaultRebuild();
    Fx::asSystem(fn () => app(CountLowStockHandler::class)->handle(new CountLowStock(Ix::storeId('sa'))));

    $low = array_map(static fn ($size): string => $size->variantId, app(LowStockReader::class)->low(StoreId::fromString(Ix::storeId('sa'))));
    sort($low);
    $expected = [$atZero, $never];
    sort($expected);

    expect($low)->toBe($expected)
        ->and((int) DB::table('inventory.low_counts')->where('store_id', Ix::storeId('sa'))->value('low'))->toBe(2)
        ->and([stockDefaultBadge($atZero), stockDefaultBadge($never), stockDefaultBadge($withStock)])->toBe(['OUT_OF_STOCK', 'OUT_OF_STOCK', null]);

    // Switched off by staff: the same sizes are low, now Orderable - the count does not move.
    Ix::switchOff('sa', [$atZero, $never]);

    expect((int) DB::table('inventory.low_counts')->where('store_id', Ix::storeId('sa'))->value('low'))->toBe(2)
        ->and([stockDefaultBadge($atZero), stockDefaultBadge($never)])->toBe(['ORDERABLE', 'ORDERABLE'])
        ->and(array_map(stockDefaultOrderable(...), [$atZero, $never]))->toBe([true, true]);
});

it('holds a size of a wired store by default, only notes one staff switched off, and counts it again once back on', function () {
    $ready = Px::ready(['60 cm', '80 cm']);
    [$dependent, $off] = $ready['variants'];
    Ix::count('sa', [$dependent => 2, $off => 2]);
    Ix::wired();
    Ix::switchOff('sa', [$off]);
    $order = strtolower((string) Str::ulid());

    app(InventoryApi::class)->hold(StoreId::fromString(Ix::storeId('sa')), $order, [new HoldLineDto($dependent, 2), new HoldLineDto($off, 5)], null);

    expect(Ix::stock('sa', $dependent)?->held)->toBe(2)
        ->and(Ix::stock('sa', $off)?->held)->toBe(0)
        ->and(stockDefaultOrderable($dependent))->toBeFalse()
        ->and(stockDefaultOrderable($off))->toBeTrue();

    // Back on: it counts on its stock again - 2 in stock, none held, orderable.
    Fx::asSystem(fn () => app(SetStockDependentHandler::class)->handle(new SetStockDependent(Ix::storeId('sa'), [$off], true)));

    expect(app(InventoryApi::class)->stock(StoreId::fromString(Ix::storeId('sa')), [$off])[$off]->available)->toBe(2)
        ->and(stockDefaultOrderable($off))->toBeTrue();
});
