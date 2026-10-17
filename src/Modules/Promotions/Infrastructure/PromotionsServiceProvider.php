<?php

declare(strict_types=1);

namespace Modules\Promotions\Infrastructure;

use Illuminate\Support\ServiceProvider;
use Modules\Access\Public\Contracts\PermissionCatalog;
use Modules\Promotions\Application\PromotionsPermissions;

/**
 * Promotions (promotions.md). Registered after Access, Catalog and Pricing: it declares its permissions
 * through Access's public catalog, and depends on Platform, Access, Catalog and Pricing only (handoff
 * §4.4) — Sales calls it, never the other way round.
 */
final class PromotionsServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Persistence/Migrations');
        $this->loadTranslationsFrom(dirname(__DIR__).'/Presentation/lang', 'promotions');

        $this->app->make(PermissionCatalog::class)->declare('promotions', ...PromotionsPermissions::definitions());
    }
}
