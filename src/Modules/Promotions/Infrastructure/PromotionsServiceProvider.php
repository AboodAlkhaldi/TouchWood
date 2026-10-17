<?php

declare(strict_types=1);

namespace Modules\Promotions\Infrastructure;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Modules\Access\Public\Contracts\PermissionCatalog;
use Modules\Catalog\Public\Contracts\CatalogUsages;
use Modules\Promotions\Application\PromotionsApiImpl;
use Modules\Promotions\Application\PromotionsPermissions;
use Modules\Promotions\Domain\Repository\Coupons;
use Modules\Promotions\Domain\Repository\OrderFacts;
use Modules\Promotions\Domain\Repository\Segments;
use Modules\Promotions\Infrastructure\Eloquent\CouponRulesUsage;
use Modules\Promotions\Infrastructure\Eloquent\DatabaseCoupons;
use Modules\Promotions\Infrastructure\Eloquent\DatabaseOrderFacts;
use Modules\Promotions\Infrastructure\Eloquent\DatabaseSegments;
use Modules\Promotions\Infrastructure\Queue\RefreshSegmentsJob;
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
        $this->app->bind(Segments::class, DatabaseSegments::class);
        $this->app->bind(PromotionsApi::class, PromotionsApiImpl::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Persistence/Migrations');
        $this->loadTranslationsFrom(dirname(__DIR__).'/Presentation/lang', 'promotions');

        $this->app->make(PermissionCatalog::class)->declare('promotions', ...PromotionsPermissions::definitions());
        // What its coupons name of Catalog's, asked before Catalog deletes it (catalog.md amendment 19).
        $this->app->make(CatalogUsages::class)->register('promotions', CouponRulesUsage::class);

        // The nightly refresh of every segment (§1.5), at 01:00 UTC — after Loyalty's expiry. Scheduled
        // work is queued as a job, never run in the scheduler's own process.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->job(RefreshSegmentsJob::class)->dailyAt('01:00')->onOneServer();
        });
    }
}
