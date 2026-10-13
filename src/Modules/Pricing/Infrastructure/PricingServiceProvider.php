<?php

declare(strict_types=1);

namespace Modules\Pricing\Infrastructure;

use Illuminate\Support\ServiceProvider;
use Modules\Access\Public\Contracts\PermissionCatalog;
use Modules\Pricing\Application\Prices\StoreWiring;
use Modules\Pricing\Application\PricingApiImpl;
use Modules\Pricing\Application\PricingPermissions;
use Modules\Pricing\Domain\Repository\PriceCandidates;
use Modules\Pricing\Domain\Repository\PricingLocks;
use Modules\Pricing\Domain\Repository\RetailPrices;
use Modules\Pricing\Infrastructure\Eloquent\DatabasePriceCandidates;
use Modules\Pricing\Infrastructure\Eloquent\DatabasePricingLocks;
use Modules\Pricing\Infrastructure\Eloquent\DatabaseRetailPrices;
use Modules\Pricing\Infrastructure\External\NoStoreWired;
use Modules\Pricing\Presentation\Console\RebuildPriceCandidatesCommand;
use Modules\Pricing\Public\Contracts\PricingApi;

/**
 * What a size costs, and every amount of an order (pricing.md). Registered after Catalog, whose
 * variants it prices, and after Access, whose public surface it uses to declare its permissions and
 * for nothing else (handoff §4.4; pricing.md §2.4).
 *
 * Steps 1-3a: the schema, the permissions, the errors and their words; retail prices, the
 * materialized candidates and the push into Catalog. `PricingApi` is bound with the step that
 * implements it.
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
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Persistence/Migrations');
        $this->loadTranslationsFrom(dirname(__DIR__).'/Presentation/lang', 'pricing');

        // Pricing sits above Access, so it declares its own permissions through Access's public
        // catalog (Access spec §2.2); Access checks them at boot like its own.
        $this->app->make(PermissionCatalog::class)->declare('pricing', ...PricingPermissions::definitions());

        if ($this->app->runningInConsole()) {
            $this->commands([RebuildPriceCandidatesCommand::class]);
        }
    }
}
