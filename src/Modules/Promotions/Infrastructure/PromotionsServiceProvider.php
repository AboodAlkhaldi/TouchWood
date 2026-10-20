<?php

declare(strict_types=1);

namespace Modules\Promotions\Infrastructure;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Modules\Access\Public\Contracts\CustomerAccountPages;
use Modules\Access\Public\Contracts\PermissionCatalog;
use Modules\Access\Public\Dto\CustomerAccountPageDto;
use Modules\Catalog\Public\Contracts\CatalogUsages;
use Modules\Platform\Public\Contracts\AdminMenu;
use Modules\Platform\Public\Dto\MenuEntryDto;
use Modules\Promotions\Application\PromotionsApiImpl;
use Modules\Promotions\Application\PromotionsPermissions;
use Modules\Promotions\Application\Query\MarketingQueries;
use Modules\Promotions\Domain\Repository\Assignments;
use Modules\Promotions\Domain\Repository\Coupons;
use Modules\Promotions\Domain\Repository\GiftLevels;
use Modules\Promotions\Domain\Repository\OrderFacts;
use Modules\Promotions\Domain\Repository\Segments;
use Modules\Promotions\Infrastructure\Eloquent\DatabaseAssignments;
use Modules\Promotions\Infrastructure\Eloquent\DatabaseCoupons;
use Modules\Promotions\Infrastructure\Eloquent\DatabaseGiftLevels;
use Modules\Promotions\Infrastructure\Eloquent\DatabaseMarketingQueries;
use Modules\Promotions\Infrastructure\Eloquent\DatabaseOrderFacts;
use Modules\Promotions\Infrastructure\Eloquent\DatabaseSegments;
use Modules\Promotions\Infrastructure\Eloquent\PromotionsCatalogUsage;
use Modules\Promotions\Infrastructure\Queue\RefreshSegmentsJob;
use Modules\Promotions\Presentation\Http\Resource\CouponPages;
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
        $this->app->bind(Assignments::class, DatabaseAssignments::class);
        $this->app->bind(GiftLevels::class, DatabaseGiftLevels::class);
        $this->app->bind(MarketingQueries::class, DatabaseMarketingQueries::class);
        $this->app->bind(PromotionsApi::class, PromotionsApiImpl::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Persistence/Migrations');
        $this->loadTranslationsFrom(dirname(__DIR__).'/Presentation/lang', 'promotions');

        $this->app->make(PermissionCatalog::class)->declare('promotions', ...PromotionsPermissions::definitions());
        // What its coupons name of Catalog's, asked before Catalog deletes it (catalog.md amendment 19).
        $this->app->make(CatalogUsages::class)->register('promotions', PromotionsCatalogUsage::class);

        // The nightly refresh of every segment (§1.5), at 01:00 UTC — after Loyalty's expiry. Scheduled
        // work is queued as a job, never run in the scheduler's own process.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->job(RefreshSegmentsJob::class)->dailyAt('01:00')->onOneServer();
        });

        /*
        | The screens (§4.1). The Marketing menu offers each list to anyone holding any of the four
        | Marketing jobs in some store - reading takes any of them (§3). What is offered is never what is
        | allowed: every handler behind these asks again (handoff §19).
        */
        $this->app->make(AdminMenu::class)->register(
            new MenuEntryDto('promotions', 'coupons', 'marketing', 'promotions.admin.coupons', CouponPages::JOBS, 10, icon: 'coupons'),
            new MenuEntryDto('promotions', 'segments', 'marketing', 'promotions.admin.segments', CouponPages::JOBS, 20, icon: 'segments'),
            new MenuEntryDto('promotions', 'gift_levels', 'marketing', 'promotions.admin.gift_levels', CouponPages::JOBS, 30, icon: 'gifts'),
        );

        // The account's Coupons tab in the shop, always offered (§4.1 M5, owner's Q12): no extra query on
        // every shop page, and "No coupons for you right now" when there are none.
        $this->app->make(CustomerAccountPages::class)->register(
            new CustomerAccountPageDto('promotions', 'coupons', 'storefront.promotions.coupons'),
        );

        if (! $this->app->routesAreCached()) {
            $this->loadRoutesFrom(dirname(__DIR__).'/Presentation/shop-routes.php');
            // The panel's own file: the admin session cookie must be set before "web" opens a session,
            // so it brings its own middleware rather than sharing the shop's group.
            $this->loadRoutesFrom(dirname(__DIR__).'/Presentation/admin-routes.php');
        }
    }
}
