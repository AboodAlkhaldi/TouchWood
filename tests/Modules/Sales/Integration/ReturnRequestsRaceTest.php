<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\ParallelTesting;
use Modules\Sales\Application\Command\ApproveOrder\ApproveOrder;
use Modules\Sales\Application\Command\ApproveOrder\ApproveOrderHandler;
use Modules\Sales\Application\Command\MarkDelivered\MarkDelivered;
use Modules\Sales\Application\Command\MarkDelivered\MarkDeliveredHandler;
use Modules\Sales\Application\Command\RequestReturn\RequestReturn;
use Modules\Sales\Application\Command\RequestReturn\RequestReturnHandler;
use Modules\Sales\Application\Command\ShipOrder\ShipOrder;
use Modules\Sales\Application\Command\ShipOrder\ShipOrderHandler;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Domain\Exception\ReturnNotAllowed;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Sales\Support\CheckoutFixtures as Cx;

use function Pest\Laravel\seed;

/*
| Two requests for a line's last pieces at once (sales.md §1.8: a piece is never claimed twice; every
| change locks the order first): the second waits for the first's lock, then finds the pieces claimed.
|
| Outside RefreshDatabase's transaction (lesson 87): it commits its own rows and empties the database
| before and after itself; skipped under --parallel.
*/

function returnRaceInParallel(): bool
{
    return ParallelTesting::token() !== false;
}

beforeEach(function () {
    if (returnRaceInParallel()) {
        return;
    }

    Artisan::call('migrate:fresh');
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    config(['database.connections.return_race' => config('database.connections.'.config('database.default'))]);
});

afterEach(function () {
    if (returnRaceInParallel()) {
        return;
    }

    DB::connection('return_race')->disconnect();
    DB::statement('SET lock_timeout = 0');
    Cache::flush();
    app()->forgetScopedInstances();
    Artisan::call('migrate:fresh');
});

it('lets a request wait on a change of the order under way, then refuses pieces already claimed', function () {
    $ready = Cx::ready();
    $order = Cx::place(Cx::start($ready['address'])->id)->id;
    Fx::actAsStaff(Fx::staffWith([SalesPermissions::ORDER_APPROVE, SalesPermissions::ORDER_FULFIL], ['sa']));
    app(ApproveOrderHandler::class)->handle(new ApproveOrder($order));
    DB::table('sales.orders')->where('id', $order)->update(['paid_minor' => DB::raw('due_minor')]);
    app(ShipOrderHandler::class)->handle(new ShipOrder($order, 'SMSA', null));
    app(MarkDeliveredHandler::class)->handle(new MarkDelivered($order));
    Fx::actAsCustomer($ready['customer']);
    $line = (string) DB::table('sales.order_lines')->where('order_id', $order)->where('variant_id', $ready['variants'][0])->value('id');
    $ask = fn () => app(RequestReturnHandler::class)->handle(new RequestReturn($order, 'DAMAGED', null, [['orderLineId' => $line, 'quantity' => 2]]));
    $other = DB::connection('return_race');

    // A change of the order, elsewhere, holds its row.
    $other->beginTransaction();
    $other->table('sales.orders')->where('id', $order)->lockForUpdate()->first();
    DB::statement("SET lock_timeout = '500ms'");

    $waited = null;

    try {
        $ask();
    } catch (QueryException $timedOut) {
        $waited = $timedOut;
    } finally {
        $other->rollBack();
    }

    expect($waited?->getCode())->toBe('55P03')
        ->and(strtolower((string) $waited?->getSql()))->toContain('"sales"."orders"')
        ->and(strtolower((string) $waited?->getSql()))->toContain('for update')
        ->and(DB::table('sales.returns')->count())->toBe(0);

    $ask();

    expect(fn () => $ask())->toThrow(ReturnNotAllowed::class)
        ->and(DB::table('sales.returns')->count())->toBe(1);
})->skip(fn (): bool => returnRaceInParallel(), 'Empties its database: run serially.');
