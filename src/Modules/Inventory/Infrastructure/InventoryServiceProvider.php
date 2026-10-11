<?php

declare(strict_types=1);

namespace Modules\Inventory\Infrastructure;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Modules\Access\Public\Contracts\PermissionCatalog;
use Modules\Inventory\Application\InventoryApiImpl;
use Modules\Inventory\Application\InventoryPermissions;
use Modules\Inventory\Application\InventorySettings;
use Modules\Inventory\Application\Stock\StoreWiring;
use Modules\Inventory\Domain\Repository\Holds;
use Modules\Inventory\Domain\Repository\InventoryLocks;
use Modules\Inventory\Domain\Repository\StockMovements;
use Modules\Inventory\Domain\Repository\StockRows;
use Modules\Inventory\Domain\Repository\StoreProducts;
use Modules\Inventory\Infrastructure\Eloquent\DatabaseHolds;
use Modules\Inventory\Infrastructure\Eloquent\DatabaseInventoryLocks;
use Modules\Inventory\Infrastructure\Eloquent\DatabaseStockMovements;
use Modules\Inventory\Infrastructure\Eloquent\DatabaseStockRows;
use Modules\Inventory\Infrastructure\Eloquent\DatabaseStoreProducts;
use Modules\Inventory\Infrastructure\External\NoStoreWired;
use Modules\Inventory\Infrastructure\Queue\ReleaseExpiredHoldsJob;
use Modules\Inventory\Public\Contracts\InventoryApi;
use Modules\Platform\Public\Contracts\SettingsRegistry;

/**
 * How many pieces a store has, what is held for orders, and the history of every change
 * (inventory.md). Registered after Catalog, whose variants it counts, and after Access, whose public
 * surface it uses to declare its permissions and for nothing else (handoff §4.4; inventory.md §2.4).
 *
 * Steps 1-2: the schema, the permission, the default threshold, the errors and their words; stock,
 * the hand changes and the ledger. `InventoryApi` is bound with the step that implements it.
 */
final class InventoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(InventoryLocks::class, DatabaseInventoryLocks::class);
        $this->app->bind(StockRows::class, DatabaseStockRows::class);
        $this->app->bind(StoreProducts::class, DatabaseStoreProducts::class);
        $this->app->bind(StockMovements::class, DatabaseStockMovements::class);
        // No store is wired until Sync is built (§1.1 rule 3).
        $this->app->bind(StoreWiring::class, NoStoreWired::class);
        $this->app->bind(Holds::class, DatabaseHolds::class);
        $this->app->bind(InventoryApi::class, InventoryApiImpl::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Persistence/Migrations');
        $this->loadTranslationsFrom(dirname(__DIR__).'/Presentation/lang', 'inventory');

        // Inventory sits above Access, so it declares its own permissions through Access's public
        // catalog (Access spec §2.2); Access checks them at boot like its own.
        $this->app->make(PermissionCatalog::class)->declare('inventory', ...InventoryPermissions::definitions());

        // The store's default low-stock threshold (§1.2), on Platform's settings screen.
        $this->app->make(SettingsRegistry::class)->define('inventory', ...InventorySettings::definitions());

        // Expired holds freed every minute (owner, 2026-10-11). Scheduled work is queued as a job,
        // never command() or call() (owner's decision, 2026-09-18).
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->job(ReleaseExpiredHoldsJob::class)->everyMinute()->onOneServer();
        });
    }
}
