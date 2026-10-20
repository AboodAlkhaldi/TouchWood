<?php

declare(strict_types=1);

namespace Modules\Sync\Infrastructure;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Access\Public\Contracts\PermissionCatalog;
use Modules\Access\Public\Enums\PermissionGroup;
use Modules\Catalog\Public\Events\ProductMadeReady;
use Modules\Catalog\Public\Events\StoreListingChanged;
use Modules\Catalog\Public\Events\VariantCodeCorrected;
use Modules\Inventory\Public\Contracts\StoreWiring as InventoryWiring;
use Modules\Platform\Public\Contracts\AdminMenu;
use Modules\Platform\Public\Contracts\ReservedPaths;
use Modules\Platform\Public\Dto\MenuEntryDto;
use Modules\Pricing\Public\Contracts\StoreWiring as PricingWiring;
use Modules\Pricing\Public\Events\DiscountReachChecked;
use Modules\Sync\Application\Connections\NoticeAddresses;
use Modules\Sync\Application\Report\SyncLog;
use Modules\Sync\Application\Report\SyncReportCount;
use Modules\Sync\Application\Report\SyncWords;
use Modules\Sync\Application\SyncPermissions;
use Modules\Sync\Domain\Provider\ProviderAdapters;
use Modules\Sync\Domain\Repository\Connections;
use Modules\Sync\Domain\Repository\ReadLines;
use Modules\Sync\Domain\Repository\ReadReports;
use Modules\Sync\Domain\Repository\Reads;
use Modules\Sync\Domain\Repository\ReportEntries;
use Modules\Sync\Infrastructure\Eloquent\DatabaseConnections;
use Modules\Sync\Infrastructure\Eloquent\DatabaseReadLines;
use Modules\Sync\Infrastructure\Eloquent\DatabaseReadReports;
use Modules\Sync\Infrastructure\Eloquent\DatabaseReads;
use Modules\Sync\Infrastructure\Eloquent\DatabaseReportEntries;
use Modules\Sync\Infrastructure\Eloquent\DatabaseSyncLog;
use Modules\Sync\Infrastructure\External\ConnectionWiring;
use Modules\Sync\Infrastructure\External\UrlNoticeAddresses;
use Modules\Sync\Infrastructure\Listener\CloseReachedDiscounts;
use Modules\Sync\Infrastructure\Listener\CloseSwitchedOnEntries;
use Modules\Sync\Infrastructure\Listener\ReadCorrectedCode;
use Modules\Sync\Infrastructure\Provider\ContainerProviderAdapters;
use Modules\Sync\Infrastructure\Provider\OdooAdapter;
use Modules\Sync\Infrastructure\Queue\PruneSyncLogJob;
use Modules\Sync\Infrastructure\Queue\QueueHourlyReadsJob;
use Modules\Sync\Infrastructure\Queue\QueueNightlyReadsJob;

/**
 * Reading a store's provider - Odoo the first - one way: the base price and the stock of each size, by
 * product code (sync.md). Registered after Pricing and Inventory, whose provider contracts it fills,
 * and after Access, whose public surface it uses to declare its permissions and for nothing else
 * (handoff §4.4).
 *
 * Step 1: the schema, the permissions, the errors and their words. Step 3: wiring a store - the
 * connections, the provider's adapters, and Sync's answer to "is this store wired". Step 4: the reads -
 * the engine, the provider's notices, the hourly and nightly schedules, a corrected code read again.
 * Stage 5's screens step 6: its screens' reads and routes, their words, and its menu entries.
 */
final class SyncServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(Connections::class, DatabaseConnections::class);
        $this->app->bind(Reads::class, DatabaseReads::class);
        $this->app->bind(ReportEntries::class, DatabaseReportEntries::class);
        $this->app->bind(ReadLines::class, DatabaseReadLines::class);
        $this->app->bind(ReadReports::class, DatabaseReadReports::class);
        $this->app->bind(SyncLog::class, DatabaseSyncLog::class);
        $this->app->bind(ProviderAdapters::class, ContainerProviderAdapters::class);
        // One a worker: it keeps each store's pace between its calls, a read after a read too (sync.md §2.4).
        $this->app->singleton(OdooAdapter::class);
        $this->app->bind(SyncWords::class, LangSyncWords::class);
        $this->app->bind(NoticeAddresses::class, UrlNoticeAddresses::class);
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
        // An off or draft size the provider priced closes its entry once it is on and ready (§1.6).
        Event::listen(StoreListingChanged::class, [CloseSwitchedOnEntries::class, 'switchedOn']);
        Event::listen(ProductMadeReady::class, [CloseSwitchedOnEntries::class, 'madeReady']);
        Event::listen(DiscountReachChecked::class, [CloseReachedDiscounts::class, 'handle']);

        // The hour's read catches a lost notice; the night's checks everything (§1.3). Scheduled work
        // is queued as a job (owner's decision, 2026-09-18).
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            // A job class each: an event and its one-server lock are named after its job's class, so one
            // class for both would let the hour's take the night's lock at 02:00.
            $schedule->job(QueueHourlyReadsJob::class)->hourly()->onOneServer();
            $schedule->job(QueueNightlyReadsJob::class)->timezone('Asia/Riyadh')->dailyAt('02:00')->onOneServer();
            // Sync's log kept 90 days (§1.6), cleared after the night's read is queued.
            $schedule->job(PruneSyncLogJob::class)->timezone('Asia/Riyadh')->dailyAt('03:00')->onOneServer();
        });

        // The panel's menu, its own Sync group after Pricing (§4.1.2, S6): the Sync Report with its open
        // entries beside it, and the Provider page for Super Admins - stage 5's screens step 6.
        $this->app->make(AdminMenu::class)->register(
            new MenuEntryDto('sync', 'report', PermissionGroup::Sync->value, 'sync.admin.report', SyncPermissions::REPORT_VIEW, 10, icon: 'sync', count: SyncReportCount::class),
            new MenuEntryDto('sync', 'provider', PermissionGroup::Sync->value, 'sync.admin.provider', SyncPermissions::CONNECTION_MANAGE, 20, icon: 'sync'),
        );

        if (! $this->app->routesAreCached()) {
            $this->loadRoutesFrom(dirname(__DIR__).'/Presentation/notify-routes.php');
            $this->loadRoutesFrom(dirname(__DIR__).'/Presentation/admin-routes.php');
        }
    }
}
