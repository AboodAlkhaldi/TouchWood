<?php

declare(strict_types=1);

namespace Modules\Catalog\Infrastructure;

use Illuminate\Support\ServiceProvider;
use Modules\Access\Public\Contracts\PermissionCatalog;
use Modules\Catalog\Application\CatalogPermissions;

/**
 * What is sold, and where (catalog.md). Registered after Access: Catalog uses Access's public
 * surface to declare its permissions, and for nothing else (handoff §4.4, owner 2026-10-02).
 */
final class CatalogServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Persistence/Migrations');
        $this->loadTranslationsFrom(dirname(__DIR__).'/Presentation/lang', 'catalog');

        // Catalog sits above Access, so it declares its own permissions through Access's public
        // catalog (Access spec §2.2); Access checks them at boot like its own.
        $this->app->make(PermissionCatalog::class)->declare('catalog', ...CatalogPermissions::definitions());
    }
}
