<?php

declare(strict_types=1);

namespace Modules\Sync\Infrastructure;

use Illuminate\Support\ServiceProvider;
use Modules\Access\Public\Contracts\PermissionCatalog;
use Modules\Sync\Application\SyncPermissions;

/**
 * Reading a store's provider - Odoo the first - one way: the base price and the stock of each size, by
 * product code (sync.md). Registered after Pricing and Inventory, whose provider contracts it fills,
 * and after Access, whose public surface it uses to declare its permissions and for nothing else
 * (handoff §4.4).
 *
 * Step 1: the schema, the permissions, the errors and their words.
 */
final class SyncServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Persistence/Migrations');
        $this->loadTranslationsFrom(dirname(__DIR__).'/Presentation/lang', 'sync');

        // Sync sits above Access, so it declares its own permissions through Access's public catalog
        // (Access spec §2.2); Access checks them at boot like its own.
        $this->app->make(PermissionCatalog::class)->declare('sync', ...SyncPermissions::definitions());
    }
}
