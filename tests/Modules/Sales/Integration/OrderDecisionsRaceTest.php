<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\ParallelTesting;
use Modules\Sales\Application\Command\CancelOwnOrder\CancelOwnOrder;
use Modules\Sales\Application\Command\CancelOwnOrder\CancelOwnOrderHandler;
use Modules\Sales\Domain\Exception\NotCancellable;
use Modules\Sales\Public\Events\OrderCancelled;
use Tests\Modules\Sales\Support\CheckoutFixtures as Cx;

use function Pest\Laravel\seed;

/*
| Two changes of one order at once (sales.md §1.4: every change locks the order first): the second waits
| for the first's lock, then finds the order as the first left it — two cancellations cancel once.
|
| Outside RefreshDatabase's transaction (lesson 87): it commits its own rows and empties the database
| before and after itself; skipped under --parallel.
*/

function decisionRaceInParallel(): bool
{
    return ParallelTesting::token() !== false;
}

beforeEach(function () {
    if (decisionRaceInParallel()) {
        return;
    }

    Artisan::call('migrate:fresh');
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
    config(['database.connections.decision_race' => config('database.connections.'.config('database.default'))]);
});

afterEach(function () {
    if (decisionRaceInParallel()) {
        return;
    }

    DB::connection('decision_race')->disconnect();
    DB::statement('SET lock_timeout = 0');
    Cache::flush();
    app()->forgetScopedInstances();
    Artisan::call('migrate:fresh');
});

it('lets a cancellation wait on a change of the order under way, then refuses the second', function () {
    $ready = Cx::ready();
    $order = Cx::place(Cx::start($ready['address'])->id)->id;
    $other = DB::connection('decision_race');

    // A change of the order, elsewhere, holds its row.
    $other->beginTransaction();
    $other->table('sales.orders')->where('id', $order)->lockForUpdate()->first();
    DB::statement("SET lock_timeout = '500ms'");

    $waited = null;

    try {
        app(CancelOwnOrderHandler::class)->handle(new CancelOwnOrder($order, 'CHANGED_MIND'));
    } catch (QueryException $timedOut) {
        $waited = $timedOut;
    } finally {
        $other->rollBack();
    }

    expect($waited?->getCode())->toBe('55P03')
        ->and(strtolower((string) $waited?->getSql()))->toContain('"sales"."orders"')
        ->and(strtolower((string) $waited?->getSql()))->toContain('for update')
        ->and(DB::table('sales.orders')->where('id', $order)->value('status'))->toBe('PENDING_APPROVAL');

    app(CancelOwnOrderHandler::class)->handle(new CancelOwnOrder($order, 'CHANGED_MIND'));

    expect(fn () => app(CancelOwnOrderHandler::class)->handle(new CancelOwnOrder($order, 'CHANGED_MIND')))->toThrow(NotCancellable::class)
        ->and(DB::table('public.outbox_messages')->where('type', OrderCancelled::class)->count())->toBe(1);
})->skip(fn (): bool => decisionRaceInParallel(), 'Empties its database: run serially.');
