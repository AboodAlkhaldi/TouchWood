<?php

declare(strict_types=1);

namespace Modules\Sync\Infrastructure;

use Illuminate\Support\ServiceProvider;
use Modules\Access\Public\Contracts\PermissionCatalog;
use Modules\Inventory\Public\Contracts\StoreWiring as InventoryWiring;
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
use Modules\Sync\Infrastructure\Provider\ContainerProviderAdapters;

/**
 * Reading a store's provider - Odoo the first - one way: the base price and the stock of each size, by
 * product code (sync.md). Registered after Pricing and Inventory, whose provider contracts it fills,
 * and after Access, whose public surface it uses to declare its permissions and for nothing else
 * (handoff §4.4).
 *
 * Step 1: the schema, the permissions, the errors and their words. Step 3: wiring a store - the
 * connections, the provider's adapters, and Sync's answer to "is this store wired".
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
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Persistence/Migrations');
        $this->loadTranslationsFrom(dirname(__DIR__).'/Presentation/lang', 'sync');

        // Sync sits above Access, so it declares its own permissions through Access's public catalog
        // (Access spec §2.2); Access checks them at boot like its own.
        $this->app->make(PermissionCatalog::class)->declare('sync', ...SyncPermissions::definitions());
    }
}
