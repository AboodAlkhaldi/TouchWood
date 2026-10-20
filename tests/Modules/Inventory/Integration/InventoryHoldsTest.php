<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Modules\Inventory\Application\Command\ReleaseExpiredHolds\ReleaseExpiredHolds;
use Modules\Inventory\Application\Command\ReleaseExpiredHolds\ReleaseExpiredHoldsHandler;
use Modules\Inventory\Application\Command\SetEndingSoon\SetEndingSoon;
use Modules\Inventory\Application\Command\SetEndingSoon\SetEndingSoonHandler;
use Modules\Inventory\Application\InventoryPermissions;
use Modules\Inventory\Domain\Exception\AlreadyHeld;
use Modules\Inventory\Domain\Exception\HoldNotEditable;
use Modules\Inventory\Domain\Exception\NoHold;
use Modules\Inventory\Domain\Exception\NotEnoughStock;
use Modules\Inventory\Domain\Exception\NotWired;
use Modules\Inventory\Domain\Exception\QuantityInvalid;
use Modules\Inventory\Domain\Exception\ShipMoreThanHeld;
use Modules\Inventory\Domain\Exception\StockBelowZero;
use Modules\Inventory\Public\Contracts\InventoryApi;
use Modules\Inventory\Public\Dto\HoldLineDto;
use Modules\Inventory\Public\Events\HoldsShort;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogFixtures as Cx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Inventory\Support\InventoryFixtures as Ix;

use function Pest\Laravel\seed;

/*
| Holds and the contract (inventory.md §1.3, §1.4, §1.9, §2.1, §4.1; §8 #1-#4, #14, #15): a hold all or
| nothing in one conditional update per size; shipping, the tick, a cancel, the expiry, an edit and a
| return, each safe to repeat; what stock() answers per kind of store; HoldsShort naming every order
| still holding a size a hand change took below its holds (owner, 2026-10-11).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

function inventoryHoldsApi(): InventoryApi
{
    return app(InventoryApi::class);
}

function inventoryHoldsSa(): StoreId
{
    return StoreId::fromString(Ix::storeId('sa'));
}

function inventoryHoldsOrder(): string
{
    return strtolower((string) Str::ulid());
}

/**
 * @return array{int, int} [in stock, held] of a size in KSA
 */
function inventoryHoldsFigures(string $variantId): array
{
    $row = Ix::stock('sa', $variantId);

    return [(int) $row?->in_stock, (int) $row?->held];
}

/**
 * @return list<string> the order's lines as "variant gift quantity shipped ended"
 */
function inventoryHoldsLines(string $orderId): array
{
    return array_values(array_map(
        static fn (stdClass $line): string => "{$line->variant_id} ".($line->gift ? 'gift' : 'bought')." {$line->quantity} {$line->shipped} {$line->ended}",
        DB::table('inventory.hold_lines as l')->join('inventory.holds as h', 'h.id', '=', 'l.hold_id')->where('h.order_id', $orderId)->orderBy('l.variant_id')->orderBy('l.gift')->get(['l.*'])->all(),
    ));
}

describe('placing an order holds (§8 #1)', function () {
    it('holds every line, all or nothing, naming the short sizes and what they had free', function () {
        [$a, $b] = Px::ready(['60 cm', '80 cm'])['variants'];
        Ix::count('sa', [$a => 5, $b => 1]);
        $queries = Cx::recordQueries();

        try {
            inventoryHoldsApi()->hold(inventoryHoldsSa(), inventoryHoldsOrder(), [new HoldLineDto($a, 2), new HoldLineDto($b, 2)], null);
            $short = null;
        } catch (NotEnoughStock $error) {
            $short = $error->context();
        }

        // One conditional update a size: no row changed means not enough.
        $updates = array_filter($queries->getArrayCopy(), static fn (array $query): bool => str_contains($query['sql'], 'SET held = held +'));

        expect($short)->toBe(['variants' => $b, 'available' => '1'])
            ->and($updates)->toHaveCount(2)
            ->and(array_values($updates)[0]['sql'])->toContain('- held >= ?')
            ->and(inventoryHoldsFigures($a))->toBe([5, 0])
            ->and(DB::table('inventory.holds')->count())->toBe(0);

        $order = inventoryHoldsOrder();
        inventoryHoldsApi()->hold(inventoryHoldsSa(), $order, [new HoldLineDto($a, 2), new HoldLineDto($b, 1)], null);

        expect(inventoryHoldsFigures($a))->toBe([5, 2])
            ->and(inventoryHoldsFigures($b))->toBe([1, 1])
            ->and(fn () => inventoryHoldsApi()->hold(inventoryHoldsSa(), inventoryHoldsOrder(), [new HoldLineDto($b, 1)], null))->toThrow(NotEnoughStock::class)
            ->and(fn () => inventoryHoldsApi()->hold(inventoryHoldsSa(), $order, [new HoldLineDto($a, 1)], null))->toThrow(AlreadyHeld::class);
    });

    it('holds a bought line and a gift line of one size, each its own', function () {
        $size = Px::ready()['variants'][0];
        Ix::count('sa', [$size => 3]);
        $order = inventoryHoldsOrder();

        inventoryHoldsApi()->hold(inventoryHoldsSa(), $order, [new HoldLineDto($size, 2), new HoldLineDto($size, 1, gift: true)], null);

        expect(inventoryHoldsFigures($size))->toBe([3, 3])
            ->and(inventoryHoldsLines($order))->toBe(["{$size} bought 2 0 OPEN", "{$size} gift 1 0 OPEN"]);
    });

    it('refuses a size that does not exist, a quantity below 1, one line twice and an order id that is not one', function () {
        $size = Px::ready()['variants'][0];
        Ix::count('sa', [$size => 3]);
        $api = inventoryHoldsApi();

        expect(Ix::invalid(fn () => $api->hold(inventoryHoldsSa(), inventoryHoldsOrder(), [new HoldLineDto(strtolower((string) Str::ulid()), 1)], null))?->attribute)->toBe('variant')
            ->and(fn () => $api->hold(inventoryHoldsSa(), inventoryHoldsOrder(), [new HoldLineDto($size, 0)], null))->toThrow(QuantityInvalid::class)
            ->and(Ix::invalid(fn () => $api->hold(inventoryHoldsSa(), inventoryHoldsOrder(), [new HoldLineDto($size, 1), new HoldLineDto(strtoupper($size), 1)], null))?->attribute)->toBe('variant')
            ->and(Ix::invalid(fn () => $api->hold(inventoryHoldsSa(), 'order-1', [new HoldLineDto($size, 1)], null))?->attribute)->toBe('order')
            ->and(inventoryHoldsFigures($size))->toBe([3, 0]);
    });

    it('in a wired store holds a stock-dependent size and a gift, and only notes an ordinary product', function () {
        [$ordinary, $dependent] = Px::ready(['60 cm', '80 cm'])['variants'];
        Ix::count('sa', [$ordinary => 0, $dependent => 1]);
        DB::table('inventory.stock')->where('variant_id', $dependent)->update(['stock_dependent' => true]);
        Ix::wired();
        $order = inventoryHoldsOrder();

        inventoryHoldsApi()->hold(inventoryHoldsSa(), $order, [new HoldLineDto($ordinary, 5), new HoldLineDto($dependent, 1)], null);

        expect(inventoryHoldsFigures($ordinary))->toBe([0, 0])
            ->and(inventoryHoldsFigures($dependent))->toBe([1, 1])
            ->and(fn () => inventoryHoldsApi()->hold(inventoryHoldsSa(), inventoryHoldsOrder(), [new HoldLineDto($ordinary, 1, gift: true)], null))->toThrow(NotEnoughStock::class);
    });
});

describe('shipping, the tick, cancel and expiry (§8 #2-#4)', function () {
    it('ships in parts in a store with no provider: in stock and held fall together, each part in the ledger', function () {
        $size = Px::ready()['variants'][0];
        Ix::count('sa', [$size => 5]);
        $order = inventoryHoldsOrder();
        inventoryHoldsApi()->hold(inventoryHoldsSa(), $order, [new HoldLineDto($size, 3)], null);

        inventoryHoldsApi()->ship($order, [new HoldLineDto($size, 2)]);
        inventoryHoldsApi()->ship($order, [new HoldLineDto($size, 1)]);
        inventoryHoldsApi()->ship($order, [new HoldLineDto($size, 1)]);

        expect(inventoryHoldsFigures($size))->toBe([2, 0])
            ->and(inventoryHoldsLines($order))->toBe(["{$size} bought 3 3 SHIPPED"])
            ->and(Ix::ledger('sa', $size))->toBe(['STOCKTAKE 0→5 SYSTEM', 'SHIPPED 5→3 SYSTEM', 'SHIPPED 3→2 SYSTEM']);
    });

    it('refuses shipping more than a line holds', function () {
        $size = Px::ready()['variants'][0];
        Ix::count('sa', [$size => 5]);
        $order = inventoryHoldsOrder();
        inventoryHoldsApi()->hold(inventoryHoldsSa(), $order, [new HoldLineDto($size, 2)], null);

        expect(fn () => inventoryHoldsApi()->ship($order, [new HoldLineDto($size, 3)]))->toThrow(ShipMoreThanHeld::class)
            ->and(inventoryHoldsFigures($size))->toBe([5, 2]);
    });

    it('ships more than is in stock in a store with no provider: the stock stops at 0 and the shortfall is noted for a recount (sales.md answer 41)', function () {
        $size = Px::ready()['variants'][0];
        Ix::count('sa', [$size => 3]);
        $order = inventoryHoldsOrder();
        inventoryHoldsApi()->hold(inventoryHoldsSa(), $order, [new HoldLineDto($size, 3)], null);
        // A hand removal took two of the three held pieces: 1 in stock, 3 held.
        Ix::adjust('sa', $size, 'REMOVE', 2, 'DAMAGED_LOST');

        inventoryHoldsApi()->ship($order, [new HoldLineDto($size, 3)]);

        expect(inventoryHoldsFigures($size))->toBe([0, 0])
            ->and(inventoryHoldsLines($order))->toBe(["{$size} bought 3 3 SHIPPED"])
            ->and(Ix::ledger('sa', $size))->toBe(['STOCKTAKE 0→3 SYSTEM', 'DAMAGED_LOST 3→1 SYSTEM', 'SHIPPED 1→0 2 more shipped than counted - recount SYSTEM']);

        // The same shipment again counts once; a removal by hand is still refused below 0.
        inventoryHoldsApi()->ship($order, [new HoldLineDto($size, 3)]);

        expect(inventoryHoldsFigures($size))->toBe([0, 0])
            ->and(Ix::ledger('sa', $size))->toHaveCount(3)
            ->and(fn () => Ix::adjust('sa', $size, 'REMOVE', 1, 'DAMAGED_LOST'))->toThrow(StockBelowZero::class)
            ->and(inventoryHoldsFigures($size))->toBe([0, 0]);
    });

    it('ships from a stock already at 0: nothing taken, the shortfall still noted on a 0 → 0 line, counted once (owner, 2026-10-19)', function () {
        $size = Px::ready()['variants'][0];
        Ix::count('sa', [$size => 2]);
        $order = inventoryHoldsOrder();
        inventoryHoldsApi()->hold(inventoryHoldsSa(), $order, [new HoldLineDto($size, 2)], null);
        Ix::adjust('sa', $size, 'REMOVE', 2, 'DAMAGED_LOST');

        inventoryHoldsApi()->ship($order, [new HoldLineDto($size, 2)]);
        inventoryHoldsApi()->ship($order, [new HoldLineDto($size, 2)]);

        expect(inventoryHoldsFigures($size))->toBe([0, 0])
            ->and(inventoryHoldsLines($order))->toBe(["{$size} bought 2 2 SHIPPED"])
            ->and(Ix::ledger('sa', $size))->toBe(['STOCKTAKE 0→2 SYSTEM', 'DAMAGED_LOST 2→0 SYSTEM', 'SHIPPED 0→0 2 more shipped than counted - recount SYSTEM']);
    });

    it('notes nothing on a part all in stock, and the next part shipped from 0 notes its shortfall', function () {
        $size = Px::ready()['variants'][0];
        Ix::count('sa', [$size => 4]);
        $order = inventoryHoldsOrder();
        inventoryHoldsApi()->hold(inventoryHoldsSa(), $order, [new HoldLineDto($size, 4)], null);
        Ix::adjust('sa', $size, 'REMOVE', 1, 'DAMAGED_LOST');

        inventoryHoldsApi()->ship($order, [new HoldLineDto($size, 3)]);
        inventoryHoldsApi()->ship($order, [new HoldLineDto($size, 1)]);

        expect(inventoryHoldsFigures($size))->toBe([0, 0])
            ->and(Ix::ledger('sa', $size))->toBe(['STOCKTAKE 0→4 SYSTEM', 'DAMAGED_LOST 4→3 SYSTEM', 'SHIPPED 3→0 SYSTEM', 'SHIPPED 0→0 1 more shipped than counted - recount SYSTEM']);
    });

    it('takes nothing on a wired store\'s shipment, and the tick ends a stock-dependent line\'s hold', function () {
        $size = Px::ready()['variants'][0];
        Ix::count('sa', [$size => 4]);
        DB::table('inventory.stock')->where('variant_id', $size)->update(['stock_dependent' => true]);
        $order = inventoryHoldsOrder();
        Ix::wired();
        inventoryHoldsApi()->hold(inventoryHoldsSa(), $order, [new HoldLineDto($size, 2)], null);

        inventoryHoldsApi()->ship($order, [new HoldLineDto($size, 2)]);
        // Shipped in full, open until its tick: the same shipment again changes nothing.
        inventoryHoldsApi()->ship($order, [new HoldLineDto($size, 2)]);

        expect(inventoryHoldsFigures($size))->toBe([4, 2]);

        inventoryHoldsApi()->reducedInProvider($order, [strtoupper($size)]);
        inventoryHoldsApi()->reducedInProvider($order, [$size]);

        expect(inventoryHoldsFigures($size))->toBe([4, 0])
            ->and(inventoryHoldsLines($order))->toBe(["{$size} bought 2 2 REDUCED"]);
    });

    it('refuses the tick in a store with no provider', function () {
        $size = Px::ready()['variants'][0];
        Ix::count('sa', [$size => 4]);
        $order = inventoryHoldsOrder();
        inventoryHoldsApi()->hold(inventoryHoldsSa(), $order, [new HoldLineDto($size, 1)], null);

        expect(fn () => inventoryHoldsApi()->reducedInProvider($order, [$size]))->toThrow(NotWired::class);
    });

    it('frees on a cancel what is still held, leaves what was shipped, and changes nothing the second time', function () {
        [$a, $b] = Px::ready(['60 cm', '80 cm'])['variants'];
        Ix::count('sa', [$a => 5, $b => 5]);
        $order = inventoryHoldsOrder();
        inventoryHoldsApi()->hold(inventoryHoldsSa(), $order, [new HoldLineDto($a, 2), new HoldLineDto($b, 3)], null);
        inventoryHoldsApi()->ship($order, [new HoldLineDto($a, 2), new HoldLineDto($b, 1)]);

        inventoryHoldsApi()->release($order);
        inventoryHoldsApi()->release($order);

        expect(inventoryHoldsFigures($a))->toBe([3, 0])
            ->and(inventoryHoldsFigures($b))->toBe([4, 0])
            ->and(inventoryHoldsLines($order))->toBe(["{$a} bought 2 2 SHIPPED", "{$b} bought 3 1 RELEASED"])
            ->and(fn () => inventoryHoldsApi()->release(inventoryHoldsOrder()))->toThrow(NoHold::class);
    });

    it('frees only the holds whose expiry has passed - the system\'s job alone', function () {
        [$a, $b] = Px::ready(['60 cm', '80 cm'])['variants'];
        Ix::count('sa', [$a => 5, $b => 5]);
        [$expired, $waiting] = [inventoryHoldsOrder(), inventoryHoldsOrder()];
        inventoryHoldsApi()->hold(inventoryHoldsSa(), $expired, [new HoldLineDto($a, 1)], CarbonImmutable::now()->subMinute()->toDateTimeImmutable());
        inventoryHoldsApi()->hold(inventoryHoldsSa(), $waiting, [new HoldLineDto($b, 1)], CarbonImmutable::now()->addHour()->toDateTimeImmutable());

        expect(Fx::asSystem(fn () => app(ReleaseExpiredHoldsHandler::class)->handle(new ReleaseExpiredHolds)))->toBe(1)
            ->and(Fx::asSystem(fn () => app(ReleaseExpiredHoldsHandler::class)->handle(new ReleaseExpiredHolds)))->toBe(0)
            ->and(inventoryHoldsLines($expired))->toBe(["{$a} bought 1 0 EXPIRED"])
            ->and(inventoryHoldsFigures($a))->toBe([5, 0])
            ->and(inventoryHoldsFigures($b))->toBe([5, 1]);

        Cx::actAsStaffWith([InventoryPermissions::STOCK_MANAGE]);

        expect(fn () => app(ReleaseExpiredHoldsHandler::class)->handle(new ReleaseExpiredHolds))->toThrow(Unauthorized::class);
    });
});

describe('an order edited before it ships (§8 #14)', function () {
    it('moves the hold to exactly the edited lines, all or nothing, and an edit made twice changes nothing', function () {
        [$a, $b, $c] = Px::ready(['60 cm', '80 cm', '100 cm'])['variants'];
        Ix::count('sa', [$a => 5, $b => 5, $c => 1]);
        $order = inventoryHoldsOrder();
        inventoryHoldsApi()->hold(inventoryHoldsSa(), $order, [new HoldLineDto($a, 2), new HoldLineDto($b, 2)], CarbonImmutable::now()->addHour()->toDateTimeImmutable());
        $expiry = DB::table('inventory.holds')->where('order_id', $order)->value('expires_at');

        // One line short: nothing changes.
        expect(fn () => inventoryHoldsApi()->adjustHold($order, [new HoldLineDto($a, 4), new HoldLineDto($c, 2)]))->toThrow(NotEnoughStock::class)
            ->and([inventoryHoldsFigures($a), inventoryHoldsFigures($b), inventoryHoldsFigures($c)])->toBe([[5, 2], [5, 2], [1, 0]])
            ->and(inventoryHoldsLines($order))->toBe(["{$a} bought 2 0 OPEN", "{$b} bought 2 0 OPEN"]);

        inventoryHoldsApi()->adjustHold($order, [new HoldLineDto($a, 4), new HoldLineDto($c, 1)]);
        inventoryHoldsApi()->adjustHold($order, [new HoldLineDto($a, 4), new HoldLineDto($c, 1)]);

        expect([inventoryHoldsFigures($a), inventoryHoldsFigures($b), inventoryHoldsFigures($c)])->toBe([[5, 4], [5, 0], [1, 1]])
            ->and(inventoryHoldsLines($order))->toBe(["{$a} bought 4 0 OPEN", "{$b} bought 2 0 RELEASED", "{$c} bought 1 0 OPEN"])
            ->and(DB::table('inventory.holds')->where('order_id', $order)->value('expires_at'))->toBe($expiry);
    });

    it('refuses an edit once a line is shipped, and an order with no hold', function () {
        $size = Px::ready()['variants'][0];
        Ix::count('sa', [$size => 5]);
        $order = inventoryHoldsOrder();
        inventoryHoldsApi()->hold(inventoryHoldsSa(), $order, [new HoldLineDto($size, 2)], null);
        inventoryHoldsApi()->ship($order, [new HoldLineDto($size, 1)]);

        expect(fn () => inventoryHoldsApi()->adjustHold($order, [new HoldLineDto($size, 1)]))->toThrow(HoldNotEditable::class)
            ->and(fn () => inventoryHoldsApi()->adjustHold(inventoryHoldsOrder(), [new HoldLineDto($size, 1)]))->toThrow(NoHold::class);
    });

    it('never edits a cancelled hold back to life', function () {
        $size = Px::ready()['variants'][0];
        Ix::count('sa', [$size => 5]);
        $order = inventoryHoldsOrder();
        inventoryHoldsApi()->hold(inventoryHoldsSa(), $order, [new HoldLineDto($size, 2)], null);
        inventoryHoldsApi()->release($order);

        expect(fn () => inventoryHoldsApi()->adjustHold($order, [new HoldLineDto($size, 3)]))->toThrow(HoldNotEditable::class)
            ->and(inventoryHoldsFigures($size))->toBe([5, 0]);
    });
});

describe('returns (§8 #15)', function () {
    it('puts the pieces back in a store with no provider, each return once', function () {
        $size = Px::ready()['variants'][0];
        Ix::count('sa', [$size => 5]);
        $order = inventoryHoldsOrder();
        inventoryHoldsApi()->hold(inventoryHoldsSa(), $order, [new HoldLineDto($size, 2)], null);
        inventoryHoldsApi()->ship($order, [new HoldLineDto($size, 2)]);

        inventoryHoldsApi()->returned($order, 'return-1', [new HoldLineDto($size, 1)]);
        inventoryHoldsApi()->returned($order, 'return-1', [new HoldLineDto($size, 1)]);

        expect(inventoryHoldsFigures($size))->toBe([4, 0])
            ->and(Ix::ledger('sa', $size))->toBe(['STOCKTAKE 0→5 SYSTEM', 'SHIPPED 5→3 SYSTEM', 'RETURNED 3→4 SYSTEM'])
            // Never more back than went out, only the order's own lines, and a return named.
            ->and(fn () => inventoryHoldsApi()->returned($order, 'return-9', [new HoldLineDto($size, 2)]))->toThrow(QuantityInvalid::class)
            ->and(Ix::invalid(fn () => inventoryHoldsApi()->returned($order, 'return-9', [new HoldLineDto($size, 1, gift: true)]))?->attribute)->toBe('variant')
            ->and(Ix::invalid(fn () => inventoryHoldsApi()->returned($order, '  ', [new HoldLineDto($size, 1)]))?->attribute)->toBe('return')
            ->and(inventoryHoldsFigures($size))->toBe([4, 0]);
    });

    it('in a wired store adds a stock-dependent size\'s or a gift\'s pieces on our side, and nothing for an ordinary product', function () {
        [$ordinary, $dependent] = Px::ready(['60 cm', '80 cm'])['variants'];
        Ix::count('sa', [$ordinary => 3, $dependent => 3]);
        DB::table('inventory.stock')->where('variant_id', $dependent)->update(['stock_dependent' => true]);
        Ix::wired();
        $order = inventoryHoldsOrder();
        inventoryHoldsApi()->hold(inventoryHoldsSa(), $order, [new HoldLineDto($ordinary, 1), new HoldLineDto($ordinary, 1, gift: true), new HoldLineDto($dependent, 1)], null);

        inventoryHoldsApi()->returned($order, 'return-2', [new HoldLineDto($ordinary, 1), new HoldLineDto($ordinary, 1, gift: true), new HoldLineDto($dependent, 1)]);

        expect(Ix::stock('sa', $ordinary)?->returned_extra)->toBe(1)
            ->and(Ix::stock('sa', $dependent)?->returned_extra)->toBe(1)
            ->and(Ix::stock('sa', $ordinary)?->in_stock)->toBe(3);
    });
});

describe('stock() (§1.3, §2.2)', function () {
    it('answers per size in a store with no provider: what can be ordered, given, and "last pieces" only with the product\'s switch', function () {
        $ready = Px::ready(['60 cm', '80 cm', '100 cm']);
        [$a, $b, $none] = $ready['variants'];
        Ix::count('sa', [$a => 3, $b => 20]);
        inventoryHoldsApi()->hold(inventoryHoldsSa(), inventoryHoldsOrder(), [new HoldLineDto($a, 1)], null);

        $before = inventoryHoldsApi()->stock(inventoryHoldsSa(), [$a, $b, strtoupper($none)]);
        Fx::asSystem(fn () => app(SetEndingSoonHandler::class)->handle(new SetEndingSoon(Ix::storeId('sa'), $ready['product'], true)));
        $after = inventoryHoldsApi()->stock(inventoryHoldsSa(), [$a, $b]);

        expect([$before[$a]->countsOnStock, $before[$a]->orderable, $before[$a]->available, $before[$a]->availableAsGift, $before[$a]->endingSoon])->toBe([true, true, 2, 2, false])
            ->and([$before[$none]->orderable, $before[$none]->available])->toBe([false, 0])
            ->and($after[$a]->endingSoon)->toBeTrue()
            ->and($after[$b]->endingSoon)->toBeFalse();
    });

    it('in a wired store leaves an ordinary product orderable at 0 and counts a stock-dependent size\'s returned pieces', function () {
        [$ordinary, $dependent] = Px::ready(['60 cm', '80 cm'])['variants'];
        Ix::count('sa', [$ordinary => 0, $dependent => 1]);
        DB::table('inventory.stock')->where('variant_id', $dependent)->update(['stock_dependent' => true, 'returned_extra' => 1]);
        Ix::wired();

        $stock = inventoryHoldsApi()->stock(inventoryHoldsSa(), [$ordinary, $dependent]);

        expect([$stock[$ordinary]->countsOnStock, $stock[$ordinary]->orderable, $stock[$ordinary]->available, $stock[$ordinary]->availableAsGift])->toBe([false, true, null, 0])
            ->and([$stock[$dependent]->available, $stock[$dependent]->endingSoon])->toBe([2, true]);
    });
});

describe('HoldsShort (§6.1; owner, 2026-10-11)', function () {
    it('names every order still holding a size a hand change took below its holds, and blocks none', function () {
        $size = Px::ready()['variants'][0];
        Ix::count('sa', [$size => 4]);
        [$first, $second] = [inventoryHoldsOrder(), inventoryHoldsOrder()];
        inventoryHoldsApi()->hold(inventoryHoldsSa(), $first, [new HoldLineDto($size, 2)], null);
        inventoryHoldsApi()->hold(inventoryHoldsSa(), $second, [new HoldLineDto($size, 2)], null);
        Event::fake([HoldsShort::class]);

        Ix::adjust('sa', $size, 'REMOVE', 2, 'DAMAGED_LOST');
        // Stock going back up, still below what is held, flags nothing again.
        Ix::adjust('sa', $size, 'ADD', 1, 'RECEIVED');
        Ix::count('sa', [$size => 3]);

        $expected = [$first, $second];
        sort($expected);

        Event::assertDispatchedTimes(HoldsShort::class, 1);
        Event::assertDispatched(HoldsShort::class, static fn (HoldsShort $event): bool => $event->variantId === $size && $event->orderIds === $expected && $event->storeId === Ix::storeId('sa'));
        expect(inventoryHoldsLines($first))->toBe(["{$size} bought 2 0 OPEN"]);
    });
});
