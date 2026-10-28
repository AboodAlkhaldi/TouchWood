<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Catalog\Application\Command\ArchiveProduct\ArchiveProduct;
use Modules\Catalog\Application\Command\ArchiveProduct\ArchiveProductHandler;
use Modules\Catalog\Application\Command\ArchiveVariant\ArchiveVariant;
use Modules\Catalog\Application\Command\ArchiveVariant\ArchiveVariantHandler;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Inventory\Application\Command\SetStockDependent\SetStockDependent;
use Modules\Inventory\Application\Command\SetStockDependent\SetStockDependentHandler;
use Modules\Inventory\Application\InventoryPermissions as ApplicationPermissions;
use Modules\Inventory\Domain\Exception\NotWired;
use Modules\Inventory\Public\Contracts\InventoryApi;
use Modules\Inventory\Public\Contracts\StoreWiring;
use Modules\Inventory\Public\InventoryPermissions;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogFixtures as Cx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Inventory\Support\InventoryFixtures as Ix;

use function Pest\Laravel\seed;

/*
| `InventoryApi::makeStockDependent` (inventory.md §2.1, §9.2 #23; owner, 2026-10-19): Promotions' gift
| level turns a size's stock-dependent switch on, in its own save. On only, never off; in a wired store
| only; the same rows, audit entry and Catalog facts as staff's switch; inside the caller's transaction
| or its own. No permission checked inside: the caller checks Manage Stock, a name now public. The switch
| is on by default (owner, 2026-10-28; §9.2 #24), so it changes only a size staff switched off.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

function stockDependentApi(): InventoryApi
{
    return app(InventoryApi::class);
}

function stockDependentSa(): StoreId
{
    return StoreId::fromString(Ix::storeId('sa'));
}

/**
 * @return array{product: string, variants: list<string>} a ready product's sizes, switched on in KSA
 */
function stockDependentSizes(): array
{
    $ready = Px::ready(['60 cm', '80 cm', '100 cm']);
    Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Ix::storeId('sa'), $ready['product'], true)));

    return ['product' => $ready['product'], 'variants' => $ready['variants']];
}

/**
 * @return bool|null whether Catalog keeps the size orderable in KSA (null when it is not told)
 */
function stockDependentOrderable(string $variantId): ?bool
{
    $orderable = DB::table('catalog.store_variant_facts')->where('store_id', Ix::storeId('sa'))->where('variant_id', $variantId)->value('orderable');

    return $orderable === null ? null : (bool) $orderable;
}

/**
 * How deep in transactions each `stock_dependent_set` audit entry was written, beyond where the work began.
 *
 * @return list<int>
 */
function stockDependentAuditDepths(Closure $work): array
{
    $start = DB::transactionLevel();
    $depths = [];

    DB::listen(function (QueryExecuted $query) use (&$depths, $start): void {
        if (str_contains($query->sql, 'audit_entries') && in_array('inventory.stock.stock_dependent_set', $query->bindings, true)) {
            $depths[] = DB::transactionLevel() - $start;
        }
    });

    $work();

    return $depths;
}

it('turns the sizes on in a wired store, audited and told to Catalog, answering the ids it turned on', function () {
    // Wired first: a size staff switched off with no stock is told orderable; stock-dependent at 0, it is not.
    Ix::wired();
    $sizes = stockDependentSizes()['variants'];
    Ix::switchOff('sa', [$sizes[0], $sizes[1]]);
    $wanted = [$sizes[1], $sizes[0]];
    $expected = [$sizes[0], $sizes[1]];
    sort($expected);

    expect(stockDependentOrderable($sizes[0]))->toBeTrue();

    $turnedOn = Fx::asSystem(fn (): array => stockDependentApi()->makeStockDependent(stockDependentSa(), [strtoupper($wanted[0]), $wanted[1]]));

    expect($turnedOn)->toBe($expected)
        ->and(Ix::stock('sa', $sizes[0])?->stock_dependent)->toBeTrue()
        ->and(Ix::stock('sa', $sizes[1])?->stock_dependent)->toBeTrue()
        ->and(Ix::stock('sa', $sizes[2]))->toBeNull()
        ->and(Ix::auditChanges('inventory.stock.stock_dependent_set', $sizes[0]))->toBe(['stock_dependent' => [false, true]])
        ->and(Fx::audits('inventory.stock.stock_dependent_set'))->toBe(4)
        ->and(stockDependentOrderable($sizes[0]))->toBeFalse()
        ->and(stockDependentOrderable($sizes[1]))->toBeFalse()
        // Never switched: on by default, so with no stock it cannot be ordered either.
        ->and(stockDependentOrderable($sizes[2]))->toBeFalse();
});

it('does nothing in a store with no provider', function () {
    $sizes = stockDependentSizes()['variants'];

    expect(Fx::asSystem(fn (): array => stockDependentApi()->makeStockDependent(stockDependentSa(), [$sizes[0]])))->toBe([])
        ->and(Ix::stock('sa', $sizes[0]))->toBeNull()
        ->and(Fx::audits('inventory.stock.stock_dependent_set'))->toBe(0);
});

it('leaves a size already on as it is, and never turns one off', function () {
    $sizes = stockDependentSizes()['variants'];
    Ix::wired();
    Ix::switchOff('sa', [$sizes[0], $sizes[1]]);
    Fx::asSystem(fn (): array => stockDependentApi()->makeStockDependent(stockDependentSa(), [$sizes[0]]));

    $turnedOn = Fx::asSystem(fn (): array => stockDependentApi()->makeStockDependent(stockDependentSa(), [$sizes[0], $sizes[1]]));

    expect($turnedOn)->toBe([$sizes[1]])
        ->and(Ix::stock('sa', $sizes[0])?->stock_dependent)->toBeTrue()
        ->and(Ix::stock('sa', $sizes[1])?->stock_dependent)->toBeTrue()
        ->and(Fx::audits('inventory.stock.stock_dependent_set', $sizes[0]))->toBe(2)
        ->and(Fx::asSystem(fn (): array => stockDependentApi()->makeStockDependent(stockDependentSa(), [$sizes[0], $sizes[1]])))->toBe([])
        ->and(Ix::stock('sa', $sizes[0])?->stock_dependent)->toBeTrue()
        ->and(Ix::stock('sa', $sizes[1])?->stock_dependent)->toBeTrue()
        ->and(Fx::audits('inventory.stock.stock_dependent_set'))->toBe(4);
});

it('changes nothing for a size never switched off: it is on by default, and no row is made for it', function () {
    $sizes = stockDependentSizes()['variants'];
    Ix::wired();

    expect(Fx::asSystem(fn (): array => stockDependentApi()->makeStockDependent(stockDependentSa(), [$sizes[0], $sizes[1]])))->toBe([])
        ->and(Ix::stock('sa', $sizes[0]))->toBeNull()
        ->and(Ix::stock('sa', $sizes[1]))->toBeNull()
        ->and(Fx::audits('inventory.stock.stock_dependent_set'))->toBe(0);
});

it('refuses a size that does not exist or is archived, or a store that does not exist, changing nothing', function () {
    $ready = stockDependentSizes();
    Fx::asSystem(fn () => app(ArchiveVariantHandler::class)->handle(new ArchiveVariant($ready['variants'][1])));
    $retired = Px::ready();
    Fx::asSystem(fn () => app(ArchiveProductHandler::class)->handle(new ArchiveProduct($retired['product'])));
    Ix::wired();

    foreach ([$ready['variants'][1], $retired['variants'][0], strtolower((string) Str::ulid())] as $size) {
        $refused = Ix::invalid(fn () => Fx::asSystem(fn (): array => stockDependentApi()->makeStockDependent(stockDependentSa(), [$ready['variants'][0], $size])));

        expect($refused?->attribute)->toBe('variant')
            ->and($refused?->variants)->toBe([$size]);
    }

    expect(Ix::invalid(fn () => stockDependentApi()->makeStockDependent(StoreId::fromString(strtolower((string) Str::ulid())), [$ready['variants'][0]]))?->attribute)->toBe('store')
        ->and(Ix::stock('sa', $ready['variants'][0]))->toBeNull()
        ->and(Fx::audits('inventory.stock.stock_dependent_set'))->toBe(0);
});

it('refuses an unknown or archived size in a store with no provider too, as in a wired one', function () {
    $ready = stockDependentSizes();
    Fx::asSystem(fn () => app(ArchiveVariantHandler::class)->handle(new ArchiveVariant($ready['variants'][1])));

    foreach ([$ready['variants'][1], strtolower((string) Str::ulid())] as $size) {
        expect(Ix::invalid(fn () => Fx::asSystem(fn (): array => stockDependentApi()->makeStockDependent(stockDependentSa(), [$ready['variants'][0], $size])))?->variants)->toBe([$size]);
    }

    expect(Fx::asSystem(fn (): array => stockDependentApi()->makeStockDependent(stockDependentSa(), [$ready['variants'][0]])))->toBe([]);
});

/**
 * A store wired when first asked, unwired from then on: an unwiring committed while the change waited
 * for the store's lock.
 */
function stockDependentUnwiredWhileWaiting(): void
{
    app()->instance(StoreWiring::class, new class implements StoreWiring
    {
        private int $asked = 0;

        public function isWired(StoreId $store): bool
        {
            return $this->asked++ === 0;
        }

        public function wiredStores(): array
        {
            return [];
        }
    });
}

it('asks the wiring again under the store\'s lock: unwired while it waited, it writes nothing', function () {
    $sizes = stockDependentSizes()['variants'];
    stockDependentUnwiredWhileWaiting();

    expect(Fx::asSystem(fn (): array => stockDependentApi()->makeStockDependent(stockDependentSa(), [$sizes[0]])))->toBe([])
        ->and(Ix::stock('sa', $sizes[0]))->toBeNull()
        ->and(Fx::audits('inventory.stock.stock_dependent_set'))->toBe(0);

    // Staff's switch the same way: refused as in a store with no provider, nothing written.
    stockDependentUnwiredWhileWaiting();

    expect(fn () => Fx::asSystem(fn () => app(SetStockDependentHandler::class)->handle(new SetStockDependent(Ix::storeId('sa'), [$sizes[0]], true))))->toThrow(NotWired::class)
        ->and(Ix::stock('sa', $sizes[0]))->toBeNull()
        ->and(Fx::audits('inventory.stock.stock_dependent_set'))->toBe(0);
});

it('runs inside the caller\'s transaction: rolled back with it, written with it', function () {
    $sizes = stockDependentSizes()['variants'];
    Ix::wired();
    Ix::switchOff('sa', [$sizes[0]]);

    expect(fn () => DB::transaction(function () use ($sizes): void {
        Fx::asSystem(fn (): array => stockDependentApi()->makeStockDependent(stockDependentSa(), [$sizes[0]]));

        throw new RuntimeException('The gift level was refused.');
    }))->toThrow(RuntimeException::class);

    expect(Ix::stock('sa', $sizes[0])?->stock_dependent)->toBeFalse()
        ->and(Fx::audits('inventory.stock.stock_dependent_set'))->toBe(1);

    // The audit entry is written inside the caller's transaction, one savepoint down.
    $depths = stockDependentAuditDepths(fn () => DB::transaction(fn (): array => Fx::asSystem(fn (): array => stockDependentApi()->makeStockDependent(stockDependentSa(), [$sizes[0]]))));

    expect($depths)->toBe([2])
        ->and(Ix::stock('sa', $sizes[0])?->stock_dependent)->toBeTrue()
        ->and(Fx::audits('inventory.stock.stock_dependent_set', $sizes[0]))->toBe(2);
});

it('called alone, opens a transaction of its own for its audit entry - the suite\'s own would hide a missing one (lesson 220)', function () {
    $sizes = stockDependentSizes()['variants'];
    Ix::wired();
    Ix::switchOff('sa', [$sizes[0], $sizes[1]]);

    $depths = stockDependentAuditDepths(fn () => Fx::asSystem(fn (): array => stockDependentApi()->makeStockDependent(stockDependentSa(), [$sizes[0], $sizes[1]])));

    expect($depths)->toBe([1, 1])
        ->and(Fx::audits('inventory.stock.stock_dependent_set'))->toBe(4);
});

it('checks no permission: the caller checks Manage Stock, whose name is public', function () {
    $sizes = stockDependentSizes()['variants'];
    Ix::wired();
    Ix::switchOff('sa', [$sizes[0]]);
    // A staff member without Manage Stock: the check is the caller's.
    Cx::actAsStaffWith(['catalog.product.view']);

    expect(stockDependentApi()->makeStockDependent(stockDependentSa(), [$sizes[0]]))->toBe([$sizes[0]])
        ->and(InventoryPermissions::STOCK_MANAGE)->toBe('inventory.stock.manage')
        ->and(ApplicationPermissions::STOCK_MANAGE)->toBe(InventoryPermissions::STOCK_MANAGE)
        ->and([ApplicationPermissions::HOLDS_EXPIRE, ApplicationPermissions::ORDERABLE_REBUILD, ApplicationPermissions::LOW_STOCK_COUNT])
        ->toBe([InventoryPermissions::HOLDS_EXPIRE, InventoryPermissions::ORDERABLE_REBUILD, InventoryPermissions::LOW_STOCK_COUNT]);
});
