<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Catalog\Public\Contracts\ListingFacts;
use Modules\Sales\Application\Command\ApproveOrder\ApproveOrder;
use Modules\Sales\Application\Command\ApproveOrder\ApproveOrderHandler;
use Modules\Sales\Application\Command\CancelOrder\CancelOrder;
use Modules\Sales\Application\Command\CancelOrder\CancelOrderHandler;
use Modules\Sales\Application\Command\MarkCameBack\MarkCameBack;
use Modules\Sales\Application\Command\MarkCameBack\MarkCameBackHandler;
use Modules\Sales\Application\Command\PushSalesRanks\PushSalesRanks;
use Modules\Sales\Application\Command\PushSalesRanks\PushSalesRanksHandler;
use Modules\Sales\Application\Command\RejectOrder\RejectOrder;
use Modules\Sales\Application\Command\RejectOrder\RejectOrderHandler;
use Modules\Sales\Application\Command\ShipOrder\ShipOrder;
use Modules\Sales\Application\Command\ShipOrder\ShipOrderHandler;
use Modules\Sales\Application\SalesPermissions;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Promotions\Support\PromotionsFixtures as Pr;
use Tests\Modules\Sales\Support\CheckoutFixtures as Cx;

use function Pest\Laravel\seed;

/*
| The shop's Best-Selling ranks, each night (sales.md §1.13): per store, products by the pieces sold in its
| orders of the last 90 days not cancelled, rejected or undelivered — a gift not sold — pushed to Catalog
| whole, every store; the system only.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

/**
 * Catalog's side recorded: each store's ranking as pushed.
 *
 * @return ArrayObject<string, array<string, int>>
 */
function ranksPushed(): ArrayObject
{
    $pushed = new ArrayObject;
    $facts = Mockery::mock(ListingFacts::class);
    $facts->shouldReceive('salesRanks')->andReturnUsing(function (StoreId $store, array $ranks) use ($pushed): void {
        $pushed[$store->value] = $ranks;
    });
    app()->instance(ListingFacts::class, $facts);

    return $pushed;
}

function ranksRun(): int
{
    return Fx::asSystem(fn () => app(PushSalesRanksHandler::class)->handle(new PushSalesRanks));
}

it('ranks a store products by the pieces sold, the gift left out', function () {
    $ready = Cx::ready();
    Pr::giftLevel($ready['variants'][1], 100_00);
    // Each order: two of the first size, one of the second, the gift of the second (not sold).
    Cx::place(Cx::start($ready['address'])->id);
    $other = Cx::ready();
    Cx::add($other['variants'][0], 5);
    Cx::place(Cx::start($other['address'])->id);
    $pushed = ranksPushed();

    expect(ranksRun())->toBe(DB::table('platform.stores')->count())
        ->and(DB::table('sales.order_lines')->where('is_gift', true)->count())->toBe(2)
        ->and($pushed[Fx::storeId('sa')])->toBe([$other['product'] => 1, $ready['product'] => 2])
        ->and($pushed[Fx::storeId('eg')])->toBe([]);
});

it('leaves out an order cancelled, rejected or undelivered; a tie goes to the newer product', function (Closure $end) {
    $ready = Cx::ready();
    Cx::place(Cx::start($ready['address'])->id);
    $other = Cx::ready();
    Cx::place(Cx::start($other['address'])->id);
    Cx::add($other['variants'][0], 5);
    $lost = Cx::place(Cx::start($other['address'])->id)->id;
    Fx::actAsStaff(Fx::staffWith([SalesPermissions::ORDER_APPROVE, SalesPermissions::ORDER_CANCEL, SalesPermissions::ORDER_FULFIL], ['sa']));
    $end($lost);
    $pushed = ranksPushed();

    ranksRun();

    // 3 pieces each.
    $newerFirst = [$ready['product'], $other['product']];
    rsort($newerFirst);
    expect(array_keys($pushed[Fx::storeId('sa')]))->toBe($newerFirst);
})->with([
    'cancelled' => [fn (string $order) => app(CancelOrderHandler::class)->handle(new CancelOrder($order, 'Out of stock'))],
    'rejected' => [fn (string $order) => app(RejectOrderHandler::class)->handle(new RejectOrder($order, 'Not for us'))],
    'undelivered' => [function (string $order) {
        app(ApproveOrderHandler::class)->handle(new ApproveOrder($order));
        DB::table('sales.orders')->where('id', $order)->update(['paid_minor' => DB::raw('due_minor')]);
        app(ShipOrderHandler::class)->handle(new ShipOrder($order, 'SMSA', null));
        app(MarkCameBackHandler::class)->handle(new MarkCameBack($order, []));
    }],
]);

it('counts only the last 90 days', function () {
    $ready = Cx::ready();
    $order = Cx::place(Cx::start($ready['address'])->id)->id;
    $pushed = ranksPushed();
    $placed = CarbonImmutable::parse((string) DB::table('sales.orders')->where('id', $order)->value('placed_at'));

    CarbonImmutable::setTestNow($placed->addDays(90));
    ranksRun();
    expect($pushed[Fx::storeId('sa')])->toBe([$ready['product'] => 1]);

    CarbonImmutable::setTestNow($placed->addDays(90)->addSecond());
    ranksRun();
    expect($pushed[Fx::storeId('sa')])->toBe([]);
});

it('runs for the system only', function () {
    Fx::actAsStaff(Fx::staff());

    expect(fn () => app(PushSalesRanksHandler::class)->handle(new PushSalesRanks))->toThrow(Unauthorized::class);
});
