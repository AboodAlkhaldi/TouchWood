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
use Shared\Application\Actor;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\StoreId;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Promotions\Support\PromotionsFixtures as Pr;
use Tests\Modules\Sales\Support\CheckoutFixtures as Cx;

use function Pest\Laravel\seed;

/*
| The shop's Best-Selling ranks, each night (sales.md §1.13): per store, products by the pieces sold in its
| orders of the last 90 days approved or later, not cancelled, rejected or undelivered — a gift not sold — pushed to Catalog
| whole, every store; the system only.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

/** Catalog's side recorded: `ranks` holds each store's ranking as pushed, by store id. */
function ranksPushed(): stdClass
{
    $pushed = new stdClass;
    $pushed->ranks = [];
    $facts = Mockery::mock(ListingFacts::class);
    $facts->shouldReceive('salesRanks')->andReturnUsing(function (StoreId $store, array $ranks) use ($pushed): void {
        $pushed->ranks[$store->value] = $ranks;
    });
    app()->instance(ListingFacts::class, $facts);

    return $pushed;
}

function ranksRun(): int
{
    return Fx::asSystem(fn () => app(PushSalesRanksHandler::class)->handle(new PushSalesRanks));
}

/** Every order waiting, approved by staff: only approved orders and later count (answer 51). */
function ranksApproveAll(): void
{
    Fx::actAsStaff(Fx::staffWith([SalesPermissions::ORDER_APPROVE], ['sa']));

    foreach (DB::table('sales.orders')->where('status', 'PENDING_APPROVAL')->pluck('id') as $order) {
        app(ApproveOrderHandler::class)->handle(new ApproveOrder((string) $order));
    }
}

it('ranks a store products by the pieces sold, the gift left out', function () {
    $ready = Cx::ready();
    // The gift is a size of the first product: counted, it would put that product first.
    Pr::giftLevel($ready['variants'][1], 100_00);
    Cx::place(Cx::start($ready['address'])->id);
    $other = Cx::ready();
    Cx::place(Cx::start($other['address'])->id);
    $more = Cx::ready();
    Cx::add($more['variants'][0], 5);
    Cx::place(Cx::start($more['address'])->id);
    ranksApproveAll();
    $pushed = ranksPushed();

    // 3, 3 and 8 pieces sold, each order with a gift of the first product's: a tie between the first two goes to the newer.
    expect(ranksRun())->toBe(DB::table('platform.stores')->count())
        ->and(DB::table('sales.order_lines')->where('is_gift', true)->count())->toBe(3)
        ->and($pushed->ranks[Fx::storeId('sa')])->toBe([$more['product'] => 1, $other['product'] => 2, $ready['product'] => 3])
        ->and($pushed->ranks[Fx::storeId('eg')])->toBe([]);
});

it('leaves out an order waiting, cancelled, rejected or undelivered', function (Closure $end) {
    $ready = Cx::ready();
    Cx::place(Cx::start($ready['address'])->id);
    $other = Cx::ready();
    Cx::place(Cx::start($other['address'])->id);
    // A larger order of the older product, which would rank it first.
    Fx::actAsCustomer($ready['customer']);
    Cx::add($ready['variants'][0], 5);
    $lost = Cx::place(Cx::start($ready['address'])->id)->id;
    Fx::actAsStaff(Fx::staffWith([SalesPermissions::ORDER_APPROVE, SalesPermissions::ORDER_CANCEL, SalesPermissions::ORDER_FULFIL], ['sa']));
    $end($lost);
    DB::table('sales.orders')->where('id', '!=', $lost)->where('status', 'PENDING_APPROVAL')->pluck('id')
        ->each(fn ($order) => app(ApproveOrderHandler::class)->handle(new ApproveOrder((string) $order)));
    $pushed = ranksPushed();

    ranksRun();

    // 3 pieces each: the newer product first.
    expect($pushed->ranks[Fx::storeId('sa')])->toBe([$other['product'] => 1, $ready['product'] => 2]);
})->with([
    // Waiting for approval: not yet sold (owner, 2026-10-28, answer 51).
    'waiting' => [fn (string $order) => null],
    'cancelled' => [fn (string $order) => app(CancelOrderHandler::class)->handle(new CancelOrder($order, 'Out of stock'))],
    'rejected' => [fn (string $order) => app(RejectOrderHandler::class)->handle(new RejectOrder($order, 'Not for us'))],
    'undelivered' => [function (string $order) {
        app(ApproveOrderHandler::class)->handle(new ApproveOrder($order));
        DB::table('sales.orders')->where('id', $order)->update(['paid_minor' => DB::raw('due_minor')]);
        app(ShipOrderHandler::class)->handle(new ShipOrder($order, otherCarrier: 'SMSA', trackingNumber: null));
        app(MarkCameBackHandler::class)->handle(new MarkCameBack($order, []));
    }],
]);

it('counts only the last 90 days', function () {
    $ready = Cx::ready();
    $order = Cx::place(Cx::start($ready['address'])->id)->id;
    ranksApproveAll();
    $pushed = ranksPushed();
    $placed = CarbonImmutable::parse((string) DB::table('sales.orders')->where('id', $order)->value('placed_at'));

    CarbonImmutable::setTestNow($placed->addDays(90));
    ranksRun();
    expect($pushed->ranks[Fx::storeId('sa')])->toBe([$ready['product'] => 1]);

    CarbonImmutable::setTestNow($placed->addDays(90)->addSecond());
    ranksRun();
    expect($pushed->ranks[Fx::storeId('sa')])->toBe([]);
});

it('runs for the system only, never for system work a person started', function () {
    Fx::actAsStaff(Fx::staff());
    expect(fn () => app(PushSalesRanksHandler::class)->handle(new PushSalesRanks))->toThrow(Unauthorized::class);

    Fx::actAs(Actor::system(Actor::staff(Fx::staff())));
    expect(fn () => app(PushSalesRanksHandler::class)->handle(new PushSalesRanks))->toThrow(Unauthorized::class);
});
