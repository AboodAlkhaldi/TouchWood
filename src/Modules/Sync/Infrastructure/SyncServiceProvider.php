<?php

declare(strict_types=1);

namespace Modules\Sync\Infrastructure;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Access\Public\Contracts\PermissionCatalog;
use Modules\Catalog\Public\Events\VariantCodeCorrected;
use Modules\Inventory\Public\Contracts\StoreWiring as InventoryWiring;
use Modules\Platform\Public\Contracts\ReservedPaths;
use Modules\Pricing\Public\Contracts\StoreWiring as PricingWiring;
use Modules\Sync\Application\SyncPermissions;
use Modules\Sync\Domain\Provider\ProviderAdapters;
use Modules\Sync\Domain\Repository\Connections;
use Modules\Sync\Domain\Repository\Reads;
use Modules\Sync\Domain\Repository\ReportEntries;
use Modules\Sync\Infrastructure\Eloquent\DatabaseConnections;
use Modules\Sync\Infrastructure\Eloquent\DatabaseReads;
use Modules\Sync\Infrastructure\Eloquent\DatabaseReportEntries;
use Modules\Sync\Infrastructure\External\ConnectionWiring;
use Modules\Sync\Infrastructure\Listener\ReadCorrectedCode;
use Modules\Sync\Infrastructure\Provider\ContainerProviderAdapters;
use Modules\Sync\Infrastructure\Queue\QueueScheduledReadsJob;

/**
 * Reading a store's provider - Odoo the first - one way: the base price and the stock of each size, by
 * product code (sync.md). Registered after Pricing and Inventory, whose provider contracts it fills,
 * and after Access, whose public surface it uses to declare its permissions and for nothing else
 * (handoff §4.4).
 *
 * Step 1: the schema, the permissions, the errors and their words. Step 3: wiring a store - the
 * connections, the provider's adapters, and Sync's answer to "is this store wired". Step 4: the reads -
 * the engine, the provider's notices, the hourly and nightly schedules, a corrected code read again.
 */
final class SyncServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(Connections::class, DatabaseConnections::class);
        $this->app->bind(Reads::class, DatabaseReads::class);
        $this->app->bind(ReportEntries::class, DatabaseReportEntries::class);
        $this->app->bind(ProviderAdapters::class, ContainerProviderAdapters::class);
        // In place of Pricing's and Inventory's "no store is wired" (sync.md §2.2): registered after
        // them, so these bindings win.
        $this->app->bind(PricingWiring::class, ConnectionWiring::class);
        $this->app->bind(InventoryWiring::class, ConnectionWiring::class);

        // `/sync/...` is the provider's: never a store's code (platform.md, reserved paths).
        $this->app->make(ReservedPaths::class)->reserve('sync', 'sync');
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Persistence/Migrations');
        $this->loadTranslationsFrom(dirname(__DIR__).'/Presentation/lang', 'sync');

        // Sync sits above Access, so it declares its own permissions through Access's public catalog
        // (Access spec §2.2); Access checks them at boot like its own.
        $this->app->make(PermissionCatalog::class)->declare('sync', ...SyncPermissions::definitions());

        // A size whose code staff corrected is matched again at once, in every wired store (§6).
        Event::listen(VariantCodeCorrected::class, [ReadCorrectedCode::class, 'handle']);

        // The hour's read catches a lost notice; the night's checks everything (§1.3). Scheduled work
        // is queued as a job (owner's decision, 2026-09-18).
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            // Named apart: both are the same job class, and onOneServer's lock is named after the event -
            // at 02:00 the hour's would otherwise take the night's lock and the night's never run.
            $schedule->job(new QueueScheduledReadsJob('HOURLY'))->name('sync-hourly-reads')->hourly()->onOneServer();
            $schedule->job(new QueueScheduledReadsJob('NIGHTLY'))->name('sync-nightly-reads')->timezone('Asia/Riyadh')->dailyAt('02:00')->onOneServer();
        });

        if (! $this->app->routesAreCached()) {
            $this->loadRoutesFrom(dirname(__DIR__).'/Presentation/notify-routes.php');
        }
    }
}
