<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Database\Seeders\PlatformSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Modules\Catalog\Public\Events\ProductArchived;
use Modules\Catalog\Public\Events\ProductMadeReady;
use Modules\Catalog\Public\Events\StoreListingChanged;
use Modules\Content\Application\Command\BuildSitemaps\BuildSitemaps;
use Modules\Content\Application\Command\BuildSitemaps\BuildSitemapsHandler;
use Modules\Content\Application\Command\QueueWantedSitemaps\QueueWantedSitemaps;
use Modules\Content\Application\Command\QueueWantedSitemaps\QueueWantedSitemapsHandler;
use Modules\Content\Infrastructure\Files\LocalSitemapFiles;
use Modules\Content\Infrastructure\Listener\QueueSitemapBuild;
use Modules\Content\Infrastructure\Queue\BuildSitemapsJob;
use Modules\Content\Infrastructure\Queue\QueueWantedSitemapsJob;
use Modules\Platform\Application\Command\ActivateStore\ActivateStore;
use Modules\Platform\Application\Command\ActivateStore\ActivateStoreHandler;
use Modules\Platform\Public\Events\StoreActivated;
use Modules\Platform\Public\Events\StoreDeactivated;
use Tests\Modules\Access\Support\AccessFixtures as Fx;

use function Pest\Laravel\seed;

/*
| When the sitemaps are built again (content.md §6, §9.3 #35, plan K10): nightly at 03:10; a store switched on or off
| at the next tick; Catalog's changes at most once a day beyond the nightly. A change only marks the build as wanted:
| nothing is built in the request that made it. The listener is called as the events reach it; that it listens to
| them is read from the dispatcher (dispatching another module's event here would run its other listeners too).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    seed(PlatformSeeder::class);
    Queue::fake();
    Storage::fake(LocalSitemapFiles::DISK);
    // The seeder switched the stores on, which wants a build: each test starts from nothing wanted.
    DB::table('content.waiting_jobs')->update(['queued_at' => null, 'wanted_at' => null, 'wanted_now' => false, 'extra_on' => null]);
});

it('wants a build when a store is switched on, through the real event', function () {
    DB::table('platform.stores')->where('code', 'eg')->update(['is_active' => false]);
    Fx::asSystem(fn () => app(ActivateStoreHandler::class)->handle(new ActivateStore('eg')));

    expect(DB::table('content.waiting_jobs')->where('job', 'BUILD_SITEMAPS')->value('wanted_now'))->toBeTrue();
});

function sitemapQueueTick(): bool
{
    return Fx::asSystem(fn (): bool => app(QueueWantedSitemapsHandler::class)->handle(new QueueWantedSitemaps));
}

function sitemapQueueCatalogChange(): void
{
    app(QueueSitemapBuild::class)->catalog(new stdClass);
}

function sitemapQueueStoreChange(): void
{
    app(QueueSitemapBuild::class)->store(new stdClass);
}

function sitemapQueueBuild(): void
{
    Fx::asSystem(fn () => app(BuildSitemapsHandler::class)->handle(new BuildSitemaps));
}

it('listens to a store switched on or off and to Catalog\'s changes to what is listed', function () {
    $listeners = app('events')->getRawListeners();

    foreach ([StoreActivated::class, StoreDeactivated::class] as $event) {
        expect($listeners[$event] ?? [])->toContain([QueueSitemapBuild::class, 'store']);
    }

    foreach ([ProductArchived::class, ProductMadeReady::class, StoreListingChanged::class] as $event) {
        expect($listeners[$event] ?? [])->toContain([QueueSitemapBuild::class, 'catalog']);
    }
});

it('builds nothing on a change: it only marks the build as wanted', function () {
    sitemapQueueCatalogChange();
    sitemapQueueStoreChange();

    Queue::assertNothingPushed();
    expect(DB::table('content.waiting_jobs')->where('job', 'BUILD_SITEMAPS')->first(['wanted_now', 'queued_at']))->toEqual((object) ['wanted_now' => true, 'queued_at' => null]);
});

it('takes up Catalog\'s changes at most once a day beyond the nightly', function () {
    expect(sitemapQueueTick())->toBeFalse();

    sitemapQueueCatalogChange();
    expect(sitemapQueueTick())->toBeTrue()->and(sitemapQueueTick())->toBeFalse();
    Queue::assertPushed(BuildSitemapsJob::class, 1);

    // The build runs; a change later that day waits for the night.
    sitemapQueueBuild();
    sitemapQueueCatalogChange();
    expect(sitemapQueueTick())->toBeFalse();

    // The next day it is taken up.
    CarbonImmutable::setTestNow(CarbonImmutable::now()->addDay());
    expect(sitemapQueueTick())->toBeTrue();
    Queue::assertPushed(BuildSitemapsJob::class, 2);
});

it('takes up a store switched on or off at the next tick whatever the day', function () {
    sitemapQueueCatalogChange();
    sitemapQueueTick();
    sitemapQueueBuild();

    sitemapQueueStoreChange();

    expect(sitemapQueueTick())->toBeTrue();
    Queue::assertPushed(BuildSitemapsJob::class, 2);
});

it('queues nothing while one waits, and a build under way leaves a later change wanted', function () {
    sitemapQueueStoreChange();
    expect(sitemapQueueTick())->toBeTrue();

    sitemapQueueStoreChange();
    expect(sitemapQueueTick())->toBeFalse();
    Queue::assertPushed(BuildSitemapsJob::class, 1);

    sitemapQueueBuild();
    sitemapQueueStoreChange();
    expect(sitemapQueueTick())->toBeTrue();
});

it('schedules the nightly build at 03:10 and the tick every ten minutes, each its own lock', function () {
    $events = collect(app(Schedule::class)->events());
    $nightly = $events->first(fn ($event): bool => $event->description === BuildSitemapsJob::class);
    $tick = $events->first(fn ($event): bool => $event->description === QueueWantedSitemapsJob::class);

    expect($nightly?->expression)->toBe('10 3 * * *')
        ->and($nightly?->timezone)->toBe('Asia/Riyadh')
        ->and($tick?->expression)->toBe('*/10 * * * *')
        ->and($nightly?->mutexName())->not->toBe($tick?->mutexName());
});
