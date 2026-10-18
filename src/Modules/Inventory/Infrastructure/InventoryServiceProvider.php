<?php

declare(strict_types=1);

namespace Modules\Inventory\Infrastructure;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Access\Public\Contracts\PermissionCatalog;
use Modules\Catalog\Public\Contracts\ImportSections;
use Modules\Catalog\Public\Events\ProductArchived;
use Modules\Catalog\Public\Events\ProductRestored;
use Modules\Catalog\Public\Events\StoreListingChanged;
use Modules\Catalog\Public\Events\StoreListingWithdrawn;
use Modules\Catalog\Public\Events\VariantArchived;
use Modules\Catalog\Public\Events\VariantRestored;
use Modules\Inventory\Application\InventoryApiImpl;
use Modules\Inventory\Application\InventoryPermissions;
use Modules\Inventory\Application\InventorySettings;
use Modules\Inventory\Application\ProviderStockImpl;
use Modules\Inventory\Application\Stock\LowStockRecounts;
use Modules\Inventory\Domain\Repository\Holds;
use Modules\Inventory\Domain\Repository\InventoryLocks;
use Modules\Inventory\Domain\Repository\LowCounts;
use Modules\Inventory\Domain\Repository\StockMovements;
use Modules\Inventory\Domain\Repository\StockRows;
use Modules\Inventory\Domain\Repository\StoreProducts;
use Modules\Inventory\Infrastructure\Eloquent\DatabaseHolds;
use Modules\Inventory\Infrastructure\Eloquent\DatabaseInventoryLocks;
use Modules\Inventory\Infrastructure\Eloquent\DatabaseLowCounts;
use Modules\Inventory\Infrastructure\Eloquent\DatabaseStockMovements;
use Modules\Inventory\Infrastructure\Eloquent\DatabaseStockRows;
use Modules\Inventory\Infrastructure\Eloquent\DatabaseStoreProducts;
use Modules\Inventory\Infrastructure\External\NoStoreWired;
use Modules\Inventory\Infrastructure\Import\StockImportSection;
use Modules\Inventory\Infrastructure\Listener\RecountLowStock;
use Modules\Inventory\Infrastructure\Listener\TellCatalogOfStock;
use Modules\Inventory\Infrastructure\Queue\CountLowStockJob;
use Modules\Inventory\Infrastructure\Queue\QueuedLowStockRecounts;
use Modules\Inventory\Infrastructure\Queue\ReleaseExpiredHoldsJob;
use Modules\Inventory\Presentation\Console\RebuildOrderableCommand;
use Modules\Inventory\Presentation\Home\LowStockCard;
use Modules\Inventory\Public\Contracts\InventoryApi;
use Modules\Inventory\Public\Contracts\ProviderStock;
use Modules\Inventory\Public\Contracts\StoreWiring;
use Modules\Platform\Public\Contracts\HomeCards;
use Modules\Platform\Public\Contracts\SettingsRegistry;
use Modules\Platform\Public\Dto\HomeCardDto;
use Modules\Platform\Public\Events\SettingChanged;

/**
 * How many pieces a store has, what is held for orders, and the history of every change
 * (inventory.md). Registered after Catalog, whose variants it counts, and after Access, whose public
 * surface it uses to declare its permissions and for nothing else (handoff §4.4; inventory.md §2.4).
 *
 * Steps 1-4: the schema, the permission, the default threshold, the errors and their words; stock,
 * the hand changes and the ledger; holds and `InventoryApi`; what Catalog is told; Low Stock (the list,
 * its count, its Home card) and its part of a store file. With Sync's step 2a: what it gives Sync (§2.5).
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
        $this->app->bind(ProviderStock::class, ProviderStockImpl::class);
        $this->app->bind(LowCounts::class, DatabaseLowCounts::class);
        $this->app->bind(LowStockRecounts::class, QueuedLowStockRecounts::class);
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

        // Its part of a store file (§1.8): the stock each item sets, kept when the item is switched on.
        $this->app->make(ImportSections::class)->register('inventory', StockImportSection::class);

        // Its card on the admin home: the low sizes, the fewest left first (§1.7). The Low Stock menu
        // entry, with LowStockCount beside it, comes with the list's screen: the menu links every
        // entry to its route.
        $this->app->make(HomeCards::class)->register(
            new HomeCardDto('inventory', 'low_stock', InventoryPermissions::STOCK_MANAGE, LowStockCard::class, 30),
        );

        // Catalog told of a size's figures when another module changes them (§2.3, §6.2).
        Event::listen(StoreListingChanged::class, [TellCatalogOfStock::class, 'switchedOn']);
        Event::listen(SettingChanged::class, [TellCatalogOfStock::class, 'settingChanged']);

        // A size archived or restored leaves or rejoins the Low Stock list in every store (step 5).
        foreach ([VariantArchived::class, VariantRestored::class, ProductArchived::class, ProductRestored::class] as $event) {
            Event::listen($event, [RecountLowStock::class, 'handle']);
        }

        Event::listen(StoreListingWithdrawn::class, [RecountLowStock::class, 'withdrawn']);

        if ($this->app->runningInConsole()) {
            $this->commands([RebuildOrderableCommand::class]);
        }

        // Expired holds freed every minute (owner, 2026-10-11). Scheduled work is queued as a job,
        // never command() or call() (owner's decision, 2026-09-18).
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->job(ReleaseExpiredHoldsJob::class)->everyMinute()->onOneServer();
            // Every store's Low Stock count counted again each night: a change missed is mended by morning.
            $schedule->job(new CountLowStockJob)->timezone('Asia/Riyadh')->dailyAt('03:30')->onOneServer();
        });
    }
}
