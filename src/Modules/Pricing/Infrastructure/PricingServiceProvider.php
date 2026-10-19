<?php

declare(strict_types=1);

namespace Modules\Pricing\Infrastructure;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Access\Public\Contracts\PermissionCatalog;
use Modules\Catalog\Public\Contracts\ImportSections;
use Modules\Catalog\Public\Contracts\ProductStoreSections;
use Modules\Catalog\Public\Events\CategoryMoved;
use Modules\Catalog\Public\Events\ProductArchived;
use Modules\Catalog\Public\Events\ProductChanged;
use Modules\Catalog\Public\Events\ProductMadeReady;
use Modules\Catalog\Public\Events\ProductRestored;
use Modules\Catalog\Public\Events\StoreListingChanged;
use Modules\Catalog\Public\Events\StoreListingWithdrawn;
use Modules\Catalog\Public\Events\VariantArchived;
use Modules\Catalog\Public\Events\VariantRestored;
use Modules\Platform\Public\Contracts\SettingGuards;
use Modules\Platform\Public\Contracts\SettingsRegistry;
use Modules\Platform\Public\Events\SettingChanged;
use Modules\Pricing\Application\Prices\PriceWindowTasks;
use Modules\Pricing\Application\Prices\UnpricedRecounts;
use Modules\Pricing\Application\Prices\Words;
use Modules\Pricing\Application\PricingApiImpl;
use Modules\Pricing\Application\PricingPermissions;
use Modules\Pricing\Application\PricingSettings;
use Modules\Pricing\Application\ProviderPricesImpl;
use Modules\Pricing\Domain\Repository\CategoryDiscounts;
use Modules\Pricing\Domain\Repository\PriceCandidates;
use Modules\Pricing\Domain\Repository\PricingLocks;
use Modules\Pricing\Domain\Repository\RetailPrices;
use Modules\Pricing\Domain\Repository\Sales;
use Modules\Pricing\Domain\Repository\UnpricedCounts;
use Modules\Pricing\Domain\Repository\WholesaleBands;
use Modules\Pricing\Infrastructure\Catalog\ProductPricesSection;
use Modules\Pricing\Infrastructure\Eloquent\DatabaseCategoryDiscounts;
use Modules\Pricing\Infrastructure\Eloquent\DatabasePriceCandidates;
use Modules\Pricing\Infrastructure\Eloquent\DatabasePricingLocks;
use Modules\Pricing\Infrastructure\Eloquent\DatabaseRetailPrices;
use Modules\Pricing\Infrastructure\Eloquent\DatabaseSales;
use Modules\Pricing\Infrastructure\Eloquent\DatabaseUnpricedCounts;
use Modules\Pricing\Infrastructure\Eloquent\DatabaseWholesaleBands;
use Modules\Pricing\Infrastructure\External\NoStoreWired;
use Modules\Pricing\Infrastructure\Import\PriceImportSection;
use Modules\Pricing\Infrastructure\Listener\RecountUnpricedSizes;
use Modules\Pricing\Infrastructure\Listener\RepriceForDiscounts;
use Modules\Pricing\Infrastructure\Queue\CheckPriceWindowsJob;
use Modules\Pricing\Infrastructure\Queue\CountUnpricedSizesJob;
use Modules\Pricing\Infrastructure\Queue\QueuedPriceWindowTasks;
use Modules\Pricing\Infrastructure\Queue\QueuedUnpricedRecounts;
use Modules\Pricing\Presentation\Console\RebuildPriceCandidatesCommand;
use Modules\Pricing\Public\Contracts\PricingApi;
use Modules\Pricing\Public\Contracts\ProviderPrices;
use Modules\Pricing\Public\Contracts\StoreWiring;

/**
 * What a size costs, and every amount of an order (pricing.md). Registered after Catalog, whose
 * variants it prices, and after Access, whose public surface it uses to declare its permissions and
 * for nothing else (handoff §4.4; pricing.md §2.4).
 *
 * Steps 1-4: the schema, the permissions, the errors and their words; retail prices, the
 * materialized candidates and the push into Catalog; `PricingApi`; sales, the ceiling, and the timed
 * tasks at a sale's start and end with the daily check behind them. Stage 5's screens step 2: the
 * product page's section, Needs a Price's kept count and what moves it.
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
        $this->app->bind(ProviderPrices::class, ProviderPricesImpl::class);
        $this->app->bind(PricingApi::class, PricingApiImpl::class);
        $this->app->bind(Sales::class, DatabaseSales::class);
        $this->app->bind(WholesaleBands::class, DatabaseWholesaleBands::class);
        $this->app->bind(CategoryDiscounts::class, DatabaseCategoryDiscounts::class);
        $this->app->bind(PriceWindowTasks::class, QueuedPriceWindowTasks::class);
        $this->app->bind(Words::class, LangWords::class);
        $this->app->bind(UnpricedCounts::class, DatabaseUnpricedCounts::class);
        $this->app->bind(UnpricedRecounts::class, QueuedUnpricedRecounts::class);
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

        // A product moving into or out of a discounted category follows at once (§1.5, §6.2).
        Event::listen(ProductChanged::class, [RepriceForDiscounts::class, 'productChanged']);
        Event::listen(ProductMadeReady::class, [RepriceForDiscounts::class, 'productMadeReady']);
        Event::listen(CategoryMoved::class, [RepriceForDiscounts::class, 'categoryMoved']);
        // In a wired store the ceiling decides what a fixed amount reaches (sync.md §9.3 #17).
        Event::listen(SettingChanged::class, [RepriceForDiscounts::class, 'ceilingChanged']);

        // Its part of a store file (§1.8): the price each item sets, kept when the item is switched on.
        $this->app->make(ImportSections::class)->register('pricing', PriceImportSection::class);

        // Its part of a product's Stores tab (§4.3.3; Catalog's amendment 23(e)): one read for every card.
        $this->app->make(ProductStoreSections::class)->register('pricing', ProductPricesSection::class);

        // Needs a Price's count kept per store (owner Q43): sizes switched on or off in a store, and any
        // size archived or restored, count it again.
        Event::listen(StoreListingChanged::class, [RecountUnpricedSizes::class, 'switched']);
        Event::listen(StoreListingWithdrawn::class, [RecountUnpricedSizes::class, 'switched']);

        foreach ([VariantArchived::class, VariantRestored::class, ProductArchived::class, ProductRestored::class] as $event) {
            Event::listen($event, [RecountUnpricedSizes::class, 'handle']);
        }

        // The daily safety check behind the timed tasks (§1.10; owner, 2026-10-09). Scheduled work is
        // queued as a job (owner's decision, 2026-09-18).
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->job(CheckPriceWindowsJob::class)->dailyAt('03:30')->onOneServer();
            // Every store's Needs a Price count counted again each night: a change missed is mended by morning.
            $schedule->job(new CountUnpricedSizesJob)->timezone('Asia/Riyadh')->dailyAt('03:40')->onOneServer();
        });

        if ($this->app->runningInConsole()) {
            $this->commands([RebuildPriceCandidatesCommand::class]);
        }
    }
}
