<?php

declare(strict_types=1);

namespace Modules\Inventory\Infrastructure;

use Illuminate\Support\ServiceProvider;
use Modules\Access\Public\Contracts\PermissionCatalog;
use Modules\Inventory\Application\InventoryPermissions;
use Modules\Inventory\Application\InventorySettings;
use Modules\Platform\Public\Contracts\SettingsRegistry;

/**
 * How many pieces a store has, what is held for orders, and the history of every change
 * (inventory.md). Registered after Catalog, whose variants it counts, and after Access, whose public
 * surface it uses to declare its permissions and for nothing else (handoff §4.4; inventory.md §2.4).
 *
 * Step 1 (the foundation): the schema, the permission, the default threshold, the errors and their
 * words. `InventoryApi` is bound with the step that implements it.
 */
final class InventoryServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Persistence/Migrations');
        $this->loadTranslationsFrom(dirname(__DIR__).'/Presentation/lang', 'inventory');

        // Inventory sits above Access, so it declares its own permissions through Access's public
        // catalog (Access spec §2.2); Access checks them at boot like its own.
        $this->app->make(PermissionCatalog::class)->declare('inventory', ...InventoryPermissions::definitions());

        // The store's default low-stock threshold (§1.2), on Platform's settings screen.
        $this->app->make(SettingsRegistry::class)->define('inventory', ...InventorySettings::definitions());
    }
}
