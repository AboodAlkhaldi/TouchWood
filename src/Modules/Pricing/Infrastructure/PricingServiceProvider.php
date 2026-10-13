<?php

declare(strict_types=1);

namespace Modules\Pricing\Infrastructure;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Modules\Access\Public\Contracts\PermissionCatalog;
use Modules\Catalog\Public\Contracts\ImportSections;
use Modules\Platform\Public\Contracts\SettingGuards;
use Modules\Platform\Public\Contracts\SettingsRegistry;
use Modules\Pricing\Application\Prices\PriceWindowTasks;
use Modules\Pricing\Application\Prices\StoreWiring;
use Modules\Pricing\Application\PricingApiImpl;
use Modules\Pricing\Application\PricingPermissions;
use Modules\Pricing\Application\PricingSettings;
use Modules\Pricing\Domain\Repository\CategoryDiscounts;
use Modules\Pricing\Domain\Repository\PriceCandidates;
use Modules\Pricing\Domain\Repository\PricingLocks;
use Modules\Pricing\Domain\Repository\RetailPrices;
use Modules\Pricing\Domain\Repository\Sales;
use Modules\Pricing\Domain\Repository\WholesaleBands;
use Modules\Pricing\Infrastructure\Eloquent\DatabaseCategoryDiscounts;
use Modules\Pricing\Infrastructure\Eloquent\DatabasePriceCandidates;
use Modules\Pricing\Infrastructure\Eloquent\DatabasePricingLocks;
use Modules\Pricing\Infrastructure\Eloquent\DatabaseRetailPrices;
use Modules\Pricing\Infrastructure\Eloquent\DatabaseSales;
use Modules\Pricing\Infrastructure\Eloquent\DatabaseWholesaleBands;
use Modules\Pricing\Infrastructure\External\NoStoreWired;
use Modules\Pricing\Infrastructure\Import\PriceImportSection;
use Modules\Pricing\Infrastructure\Queue\CheckPriceWindowsJob;
use Modules\Pricing\Infrastructure\Queue\QueuedPriceWindowTasks;
use Modules\Pricing\Presentation\Console\RebuildPriceCandidatesCommand;
use Modules\Pricing\Public\Contracts\PricingApi;

/**
 * What a size costs, and every amount of an order (pricing.md). Registered after Catalog, whose
 * variants it prices, and after Access, whose public surface it uses to declare its permissions and
 * for nothing else (handoff §4.4; pricing.md §2.4).
 *
 * Steps 1-4: the schema, the permissions, the errors and their words; retail prices, the
 * materialized candidates and the push into Catalog; `PricingApi`; sales, the ceiling, and the timed
 * tasks at a sale's start and end with the daily check behind them.
 */
final class PricingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PricingLocks::class, DatabasePricingLocks::class);
        $this->app->bind(RetailPrices::class, DatabaseRetailPrices::class);
        $this->app->bind(PriceCandidates::class, DatabasePriceCandidates::class);
        // No store is wired until Sync is built (§1.1 rule 9).
        $this->app->bind(StoreWiring::class, NoStoreWired::class);
        $this->app->bind(PricingApi::class, PricingApiImpl::class);
        $this->app->bind(Sales::class, DatabaseSales::class);
        $this->app->bind(WholesaleBands::class, DatabaseWholesaleBands::class);
        $this->app->bind(CategoryDiscounts::class, DatabaseCategoryDiscounts::class);
        $this->app->bind(PriceWindowTasks::class, QueuedPriceWindowTasks::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Persistence/Migrations');
        $this->loadTranslationsFrom(dirname(__DIR__).'/Presentation/lang', 'pricing');

        // Pricing sits above Access, so it declares its own permissions through Access's public
        // catalog (Access spec §2.2); Access checks them at boot like its own.
        $this->app->make(PermissionCatalog::class)->declare('pricing', ...PricingPermissions::definitions());

        // The store's discount ceiling (§1.12), one Platform setting: a lower one is held back while
        // sales go deeper (owner, 2026-10-13).
        $this->app->make(SettingsRegistry::class)->define('pricing', ...PricingSettings::definitions());
        $this->app->make(SettingGuards::class)->register(PricingSettings::MAX_DISCOUNT_PERCENT, CeilingGuard::class);

        // Its part of a store file (§1.8): the price each item sets, kept when the item is switched on.
        $this->app->make(ImportSections::class)->register('pricing', PriceImportSection::class);

        // The daily safety check behind the timed tasks (§1.10; owner, 2026-10-09). Scheduled work is
        // queued as a job (owner's decision, 2026-09-18).
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->job(CheckPriceWindowsJob::class)->dailyAt('03:30')->onOneServer();
        });

        if ($this->app->runningInConsole()) {
            $this->commands([RebuildPriceCandidatesCommand::class]);
        }
    }
}
