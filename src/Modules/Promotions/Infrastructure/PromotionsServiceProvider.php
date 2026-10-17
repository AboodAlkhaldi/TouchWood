<?php

declare(strict_types=1);

namespace Modules\Promotions\Infrastructure;

use Illuminate\Support\ServiceProvider;
use Modules\Access\Public\Contracts\PermissionCatalog;
use Modules\Promotions\Application\PromotionsApiImpl;
use Modules\Promotions\Application\PromotionsPermissions;
use Modules\Promotions\Domain\Repository\Coupons;
use Modules\Promotions\Domain\Repository\OrderFacts;
use Modules\Promotions\Infrastructure\Eloquent\DatabaseCoupons;
use Modules\Promotions\Infrastructure\Eloquent\DatabaseOrderFacts;
use Modules\Promotions\Public\Contracts\PromotionsApi;

/**
 * Promotions (promotions.md). Registered after Access, Catalog and Pricing: it declares its permissions
 * through Access's public catalog, and depends on Platform, Access, Catalog and Pricing only (handoff
 * §4.4) — Sales calls it, never the other way round.
 */
final class PromotionsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(Coupons::class, DatabaseCoupons::class);
        $this->app->bind(OrderFacts::class, DatabaseOrderFacts::class);
        $this->app->bind(PromotionsApi::class, PromotionsApiImpl::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Persistence/Migrations');
        $this->loadTranslationsFrom(dirname(__DIR__).'/Presentation/lang', 'promotions');

        $this->app->make(PermissionCatalog::class)->declare('promotions', ...PromotionsPermissions::definitions());
    }
}
