<?php

declare(strict_types=1);

namespace Modules\Sales\Infrastructure;

use Illuminate\Support\ServiceProvider;
use Modules\Access\Public\Contracts\PermissionCatalog;
use Modules\Platform\Public\Contracts\SettingsRegistry;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Application\SalesSettings;
use Modules\Sales\Infrastructure\External\NothingSoldYet;
use Modules\Sales\Public\Contracts\SalesApi;

/**
 * Sales (sales.md). Registered after Access and Platform: it declares its permissions through
 * Access's public catalog and its settings through Platform. Its public contract is answered by a
 * placeholder until its returns are built (step S10), so the modules built against it (Feedback) run.
 */
final class SalesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SalesApi::class, NothingSoldYet::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Persistence/Migrations');
        $this->loadTranslationsFrom(dirname(__DIR__).'/Presentation/lang', 'sales');

        $this->app->make(PermissionCatalog::class)->declare('sales', ...SalesPermissions::definitions());
        // The flat shipping fee, free shipping from and the return window (§1.10), on Platform's settings page.
        $this->app->make(SettingsRegistry::class)->define('sales', ...SalesSettings::definitions());
    }
}
