<?php

declare(strict_types=1);

use Database\Seeders\CatalogSeeder;
use Database\Seeders\PlatformSeeder;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Modules\Catalog\Application\Command\ArchiveVariant\ArchiveVariant;
use Modules\Catalog\Application\Command\ArchiveVariant\ArchiveVariantHandler;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStore;
use Modules\Catalog\Application\Command\ChooseInStore\ChooseInStoreHandler;
use Modules\Inventory\Application\Command\CountLowStock\CountLowStock;
use Modules\Inventory\Application\Command\CountLowStock\CountLowStockHandler;
use Modules\Inventory\Application\Command\SetLowStockThreshold\SetLowStockThreshold;
use Modules\Inventory\Application\Command\SetLowStockThreshold\SetLowStockThresholdHandler;
use Modules\Inventory\Application\InventoryPermissions;
use Modules\Inventory\Application\Stock\LowStockCount;
use Modules\Inventory\Infrastructure\Queue\CountLowStockJob;
use Modules\Inventory\Infrastructure\Queue\QueuedLowStockRecounts;
use Shared\Application\Unauthorized;
use Tests\Modules\Access\Support\AccessFixtures as Fx;
use Tests\Modules\Catalog\Support\CatalogFixtures as Cx;
use Tests\Modules\Catalog\Support\CatalogProducts as Px;
use Tests\Modules\Inventory\Support\InventoryFixtures as Ix;

use function Pest\Laravel\seed;

/*
| The Low Stock count kept per store (inventory.md §1.7, step 5; owner, 2026-10-18): counted again by
| a job after every change that can move it - the list's own reader, so they agree - and read for the
| menu in one query; a store never counted is counted live once.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    seed(CatalogSeeder::class);
});

/**
 * Three sizes switched on in KSA, none stocked: all three low.
 *
 * @return list<string>
 */
function lowCountSizes(): array
{
    $ready = Px::ready(['60 cm', '80 cm', '100 cm']);
    Fx::asSystem(fn () => app(ChooseInStoreHandler::class)->handle(new ChooseInStore(Ix::storeId('sa'), $ready['product'], true)));

    return $ready['variants'];
}

function lowCountKept(string $store = 'sa'): ?int
{
    $low = DB::table('inventory.low_counts')->where('store_id', Ix::storeId($store))->value('low');

    return $low === null ? null : (int) $low;
}

it('keeps the count in step with the list after each change - stock set, a threshold, a size archived', function () {
    [$a, $b] = lowCountSizes();

    expect(lowCountKept())->toBe(3);

    Ix::count('sa', [$a => 50]);

    expect(lowCountKept())->toBe(2);

    // The store's default is 10: a threshold of 60 makes 50 low again.
    Fx::asSystem(fn () => app(SetLowStockThresholdHandler::class)->handle(new SetLowStockThreshold(Ix::storeId('sa'), [$a => 60])));

    expect(lowCountKept())->toBe(3);

    Fx::asSystem(fn () => app(ArchiveVariantHandler::class)->handle(new ArchiveVariant($b)));

    expect(lowCountKept())->toBe(2);
});

it('reads the kept counts for the menu in one query, Catalog never read', function () {
    lowCountSizes();
    Cx::actAsStaffWith([InventoryPermissions::STOCK_MANAGE], ['sa']);
    app(LowStockCount::class)->count();
    $queries = Cx::recordQueries();

    expect(app(LowStockCount::class)->count())->toBe(3);

    $ours = array_values(array_filter((array) $queries, static fn (array $query): bool => str_contains($query['sql'], '"inventory".') || str_contains($query['sql'], '"catalog".')));

    expect(count($ours))->toBe(1)
        ->and($ours[0]['sql'])->toContain('"inventory"."low_counts"');
});

it('counts a store never counted live, this once, and queues its count - keeping no number itself', function () {
    lowCountSizes();
    DB::table('inventory.low_counts')->delete();
    Queue::fake();
    Cx::actAsStaffWith([InventoryPermissions::STOCK_MANAGE], ['sa']);

    expect(app(LowStockCount::class)->count())->toBe(3)
        ->and(lowCountKept())->toBeNull();
    Queue::assertPushed(CountLowStockJob::class, fn (CountLowStockJob $job): bool => $job->storeId === Ix::storeId('sa') && $job->delay === QueuedLowStockRecounts::WAIT_SECONDS);
});

it('counts live a store whose first count is queued but has not run yet', function () {
    Queue::fake();
    lowCountSizes();
    Cx::actAsStaffWith([InventoryPermissions::STOCK_MANAGE], ['sa']);

    expect(DB::table('inventory.low_counts')->where('store_id', Ix::storeId('sa'))->value('recount_queued'))->toBeTrue()
        ->and(app(LowStockCount::class)->count())->toBe(3);
});

it('queues one count a store however many changes come before it runs', function () {
    [$a, $b] = lowCountSizes();
    Queue::fake();

    Ix::count('sa', [$a => 50]);
    Ix::count('sa', [$b => 50]);

    Queue::assertPushed(CountLowStockJob::class, 1);
});

it('counts every store each night at 03:30 Riyadh time on one server, and is the system\'s alone', function () {
    $events = collect(app(Schedule::class)->events())->filter(static fn ($event): bool => $event->description === CountLowStockJob::class)->values();

    $night = $events->first();
    assert($night instanceof Event);

    expect($events)->toHaveCount(1)
        ->and([$night->expression, $night->timezone, $night->onOneServer])->toBe(['30 3 * * *', 'Asia/Riyadh', true]);

    Cx::actAsStaffWith([InventoryPermissions::STOCK_MANAGE], ['sa']);

    expect(fn () => app(CountLowStockHandler::class)->handle(new CountLowStock))->toThrow(Unauthorized::class);

    lowCountSizes();
    DB::table('inventory.low_counts')->delete();

    expect(Fx::asSystem(fn (): int => app(CountLowStockHandler::class)->handle(new CountLowStock)))->toBeGreaterThan(1)
        ->and(lowCountKept())->toBe(3)
        ->and(lowCountKept('ae'))->toBe(0);
});
