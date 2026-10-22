<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Inventory\Domain\ValueObject\StockLimits;
use Modules\Inventory\Infrastructure\External\NoStoreWired;
use Modules\Inventory\Public\Contracts\InventoryApi;
use Modules\Inventory\Public\Contracts\ProviderStock;
use Modules\Inventory\Public\Contracts\StoreWiring;
use Modules\Inventory\Public\Dto\HoldLineDto;
use Modules\Inventory\Public\Dto\ProviderStockResult;
use Modules\Inventory\Public\Events\HoldsShort;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Inventory\Support\InventoryFixtures as Ix;

use function Pest\Laravel\seed;

/*
| The provider's stock, written by Sync (inventory.md §2.5, §1.1 rule 3, §1.9; sync.md §1.5, §1.8): the
| provider's number a PROVIDER movement, the same number nothing, a read run again counted once, the
| returned extra followed; archived, unknown and impossible numbers left alone and named; a store not
| wired left alone; unwiring counted first, the returned extra taken in, every size told again.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

/**
 * @param  array<string, int>  $stock
 */
function providerStockApply(array $stock, string $readRef = 'read-1'): ProviderStockResult
{
    return Fx::asSystem(fn (): ProviderStockResult => app(ProviderStock::class)->apply(StoreId::fromString(Ix::storeId('sa')), $stock, $readRef));
}

function providerStockSa(): StoreId
{
    return StoreId::fromString(Ix::storeId('sa'));
}

/**
 * A size's row in KSA set as a test needs it - in a wired store stock is the provider's, so no hand
 * change may set it.
 *
 * @param  array<string, mixed>  $columns
 */
function providerStockRow(string $variantId, array $columns): void
{
    if (Ix::stock('sa', $variantId) === null) {
        DB::table('inventory.stock')->insert([
            'store_id' => Ix::storeId('sa'), 'variant_id' => $variantId,
            'product_id' => DB::table('catalog.variants')->where('id', $variantId)->value('product_id'), 'updated_at' => now(),
        ]);
    }

    DB::table('inventory.stock')->where('store_id', Ix::storeId('sa'))->where('variant_id', $variantId)->update($columns);
}

/**
 * @return bool|null whether Catalog was told the size can be ordered in KSA, or null when never told
 */
function providerStockOrderable(string $variantId): ?bool
{
    $told = DB::table('catalog.store_variant_facts')->where('store_id', Ix::storeId('sa'))->where('variant_id', $variantId)->value('orderable');

    return $told === null ? null : (bool) $told;
}

describe('the provider\'s numbers', function () {
    it('sets each size to the provider\'s number, a PROVIDER movement, and the same number again changes nothing', function () {
        [$a, $b] = Px::ready(['60 cm', '80 cm'])['variants'];
        Ix::count('sa', [$a => 4]);
        Ix::wired();

        $result = providerStockApply([$a => 9, $b => 0]);

        // B has no row: at 0 it is 0 already, so it gets none - and stays off the Low Stock list.
        expect($result->wired)->toBeTrue()
            ->and($result->changed)->toBe([$a])
            ->and([$result->archived, $result->notFound, $result->invalid])->toBe([[], [], []])
            ->and(Ix::stock('sa', $a)?->in_stock)->toBe(9)
            ->and(Ix::ledger('sa', $a))->toBe(['STOCKTAKE 0→4 SYSTEM', 'PROVIDER 4→9 SYSTEM'])
            ->and(Ix::stock('sa', $b))->toBeNull();

        // Above 0, a size with no row gets one.
        expect(providerStockApply([$b => 2], 'read-3')->changed)->toBe([$b])
            ->and(Ix::ledger('sa', $b))->toBe(['PROVIDER 0→2 SYSTEM']);

        expect(providerStockApply([$a => 9], 'read-2')->changed)->toBe([])
            ->and(Ix::ledger('sa', $a))->toHaveCount(2);
    });

    it('counts a read run again once, even after a later read changed the number', function () {
        $size = Px::ready()['variants'][0];
        Ix::wired();

        providerStockApply([$size => 5], 'read-a');
        providerStockApply([$size => 7], 'read-b');
        $again = providerStockApply([$size => 5], 'read-a');

        expect($again->changed)->toBe([])
            ->and(Ix::stock('sa', $size)?->in_stock)->toBe(7)
            ->and(Ix::ledger('sa', $size))->toBe(['PROVIDER 0→5 SYSTEM', 'PROVIDER 5→7 SYSTEM']);
    });

    it('follows the provider: what was returned meanwhile goes when its number changes, and stays while it does not', function () {
        $size = Px::ready()['variants'][0];
        Ix::wired();
        providerStockRow($size, ['in_stock' => 3, 'stock_dependent' => true, 'returned_extra' => 2]);

        providerStockApply([$size => 3]);
        expect(Ix::stock('sa', $size)?->returned_extra)->toBe(2);

        providerStockApply([$size => 4], 'read-2');
        expect(Ix::stock('sa', $size)?->returned_extra)->toBe(0)
            ->and(Ix::stock('sa', $size)?->in_stock)->toBe(4);
    });

    it('tells Catalog whether a stock-dependent size can be ordered, as every change does', function () {
        $size = Px::ready()['variants'][0];
        Ix::wired();
        providerStockRow($size, ['in_stock' => 2, 'stock_dependent' => true, 'orderable' => true]);

        providerStockApply([$size => 0]);
        expect(providerStockOrderable($size))->toBeFalse();

        providerStockApply([$size => 3], 'read-2');
        expect(providerStockOrderable($size))->toBeTrue();
    });

    it('takes a size of a draft product, or one not switched on in the store, too', function () {
        $product = Px::product('Hinge');
        $size = Px::variant($product, '7000001');
        Ix::wired();

        expect(providerStockApply([$size => 6])->changed)->toBe([$size])
            ->and(Ix::stock('sa', $size)?->in_stock)->toBe(6);
    });

    it('leaves an archived size, a size of an archived product, a size not found and a number that is not one alone, naming each', function () {
        [$kept, $archived] = Px::ready(['60 cm', '80 cm'])['variants'];
        $ofArchived = Px::ready(['90 cm'])['variants'][0];
        DB::table('catalog.variants')->where('id', $archived)->update(['is_archived' => true]);
        DB::table('catalog.products')->where('id', DB::table('catalog.variants')->where('id', $ofArchived)->value('product_id'))->update(['stage' => 'ARCHIVED', 'archived_from' => 'READY']);
        $missing = strtolower((string) Str::ulid());
        [$negative, $tooMany, $most] = Px::ready(['1', '2', '3'])['variants'];
        Ix::wired();

        $result = providerStockApply([$kept => 1, $archived => 2, $ofArchived => 3, $missing => 4, $negative => -1, $tooMany => StockLimits::MOST_PIECES + 1, $most => StockLimits::MOST_PIECES]);

        expect($result->changed)->toEqualCanonicalizing([$kept, $most])
            ->and($result->archived)->toEqualCanonicalizing([$archived, $ofArchived])
            ->and($result->notFound)->toBe([$missing])
            ->and($result->invalid)->toBe([$negative, $tooMany])
            ->and(Ix::stock('sa', $archived))->toBeNull()
            ->and(Ix::stock('sa', $negative))->toBeNull()
            ->and(Ix::stock('sa', $most)?->in_stock)->toBe(StockLimits::MOST_PIECES);
    });

    it('writes nothing in a store that is not wired - unwired while the read ran', function () {
        $size = Px::ready()['variants'][0];

        $result = providerStockApply([$size => 5]);

        expect($result->wired)->toBeFalse()
            ->and($result->changed)->toBe([])
            ->and(Ix::stock('sa', $size))->toBeNull();
    });

    it('refuses a read reference the ledger cannot hold', function (string $readRef) {
        $size = Px::ready()['variants'][0];
        Ix::wired();

        expect(fn () => providerStockApply([$size => 5], $readRef))->toThrow(InvalidArgumentException::class)
            ->and(Ix::stock('sa', $size))->toBeNull();
    })->with(['empty' => '', 'a space' => 'read 1', 'a colon' => 'read:1', 'too long' => str_repeat('r', 65)]);

    it('takes the longest reference there is', function () {
        $size = Px::ready()['variants'][0];
        Ix::wired();

        expect(providerStockApply([$size => 5], str_repeat('r', 64))->changed)->toBe([$size]);
    });

    it('sends no HoldsShort: §6.1 names hand changes only', function () {
        $size = Px::ready()['variants'][0];
        Ix::wired();
        providerStockRow($size, ['in_stock' => 4, 'stock_dependent' => true]);
        app(InventoryApi::class)->hold(providerStockSa(), strtolower((string) Str::ulid()), [new HoldLineDto($size, 4)], null);
        Event::fake([HoldsShort::class]);

        providerStockApply([$size => 1]);

        Event::assertNotDispatched(HoldsShort::class);
    });

    it('names a second id written for the same size, and takes the first', function () {
        $size = Px::ready()['variants'][0];
        Ix::wired();

        $result = providerStockApply([$size => 3, strtoupper($size) => 8]);

        expect($result->changed)->toBe([$size])
            ->and($result->invalid)->toBe([strtoupper($size)])
            ->and(Ix::stock('sa', $size)?->in_stock)->toBe(3);
    });

    it('stops at the batch where it finds the store unwired, asked again under the lock', function () {
        $size = Px::ready()['variants'][0];
        // Wired when the read starts, unwired by the time its first batch takes the store's lock.
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

        $result = providerStockApply([$size => 5]);

        expect($result->wired)->toBeFalse()
            ->and($result->changed)->toBe([])
            ->and(Ix::stock('sa', $size))->toBeNull();
    });
});

/**
 * Five sizes switched on in KSA, wired: an ordinary one at 0 and one with stock, a stock-dependent one
 * at 0 with 2 returned meanwhile, one never counted, and a stock-dependent one at 0 - not orderable
 * before either. And one more at 0 that is not switched on there.
 *
 * @return array{string, string, string, string, string, string}
 */
function providerStockFour(): array
{
    $ready = Px::ready(['60 cm', '80 cm', '100 cm', '120 cm', '140 cm']);
    [$atZero, $withStock, $returned, $never, $dependentAtZero] = $ready['variants'];
    $notOn = Px::ready(['160 cm'])['variants'][0];
    // Counted and switched on before the store is wired, as a store is.
    Ix::count('sa', [$atZero => 0, $withStock => 3, $notOn => 0]);
    Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Ix::storeId('sa'), $ready['product'], true)));
    Ix::wired();
    providerStockRow($returned, ['in_stock' => 0, 'stock_dependent' => true, 'returned_extra' => 2]);
    providerStockRow($dependentAtZero, ['in_stock' => 0, 'stock_dependent' => true]);
    Fx::asSystem(fn () => app(ProviderStock::class)->wired(providerStockSa()));

    return [$atZero, $withStock, $returned, $never, $dependentAtZero, $notOn];
}

describe('wiring (sync.md §1.2)', function () {
    it('tells Catalog every size again as a wired store\'s: an ordinary product orderable at 0, one never counted too', function () {
        $ready = Px::ready(['60 cm', '80 cm']);
        [$atZero, $never] = $ready['variants'];
        Ix::count('sa', [$atZero => 0]);
        Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Ix::storeId('sa'), $ready['product'], true)));
        expect(array_map(providerStockOrderable(...), [$atZero, $never]))->toBe([false, false]);
        Ix::wired();

        Fx::asSystem(fn () => app(ProviderStock::class)->wired(providerStockSa()));

        expect(array_map(providerStockOrderable(...), [$atZero, $never]))->toBe([true, true])
            // Nothing written: the stocktake's line is the only one.
            ->and(Ix::ledger('sa', $atZero))->toBe(['STOCKTAKE 0→0 SYSTEM']);
    });

    it('refuses to work orderability out for a store not wired', function () {
        expect(fn () => app(ProviderStock::class)->wired(providerStockSa()))->toThrow(LogicException::class);
    });
});

describe('unwiring (sync.md §1.8)', function () {
    it('counts first the sizes that will stop being orderable: ordinary ones the provider left at 0', function () {
        providerStockFour();

        expect(app(ProviderStock::class)->stopsWhenUnwired(providerStockSa()))->toBe(2);
    });

    it('takes the returned pieces in, tells Catalog every size again for a store with no provider, and answers how many stopped', function () {
        [$atZero, $withStock, $returned, $never, $dependentAtZero, $notOn] = providerStockFour();
        // What a wired store told Catalog: the ordinary sizes, the one never counted too, orderable.
        expect(array_map(providerStockOrderable(...), [$atZero, $never]))->toBe([true, true]);
        app()->instance(StoreWiring::class, new NoStoreWired);

        $stopped = Fx::asSystem(fn (): int => app(ProviderStock::class)->unwired(providerStockSa()));

        expect($stopped)->toBe(2)
            ->and(Ix::stock('sa', $returned)?->in_stock)->toBe(2)
            ->and(Ix::stock('sa', $returned)?->returned_extra)->toBe(0)
            ->and(Ix::ledger('sa', $returned))->toBe(['CORRECTION 0→2 UNWIRED SYSTEM'])
            ->and(Ix::ledger('sa', $withStock))->toBe(['STOCKTAKE 0→3 SYSTEM'])
            ->and(array_map(providerStockOrderable(...), [$atZero, $withStock, $returned, $never, $dependentAtZero, $notOn]))->toBe([false, true, true, false, false, false]);
    });

    it('refuses to work orderability out for a store still wired', function () {
        providerStockFour();

        expect(fn () => app(ProviderStock::class)->unwired(providerStockSa()))->toThrow(LogicException::class);
    });
});
