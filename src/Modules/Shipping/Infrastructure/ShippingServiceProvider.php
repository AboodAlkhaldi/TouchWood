<?php

declare(strict_types=1);

namespace Modules\Shipping\Infrastructure;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Modules\Access\Public\Contracts\PermissionCatalog;
use Modules\Platform\Public\Contracts\AdminMenu;
use Modules\Platform\Public\Contracts\MediaUsages;
use Modules\Platform\Public\Contracts\SettingsRegistry;
use Modules\Platform\Public\Contracts\ShipToCountryGuards;
use Modules\Platform\Public\Dto\MenuEntryDto;
use Modules\Shipping\Application\Carrier\CarriersHoldCountry;
use Modules\Shipping\Application\Price\PriceReader;
use Modules\Shipping\Application\ShippingApiImpl;
use Modules\Shipping\Application\ShippingPermissions;
use Modules\Shipping\Application\ShippingSettings;
use Modules\Shipping\Application\Uncovered\UncoveredCounts;
use Modules\Shipping\Application\Uncovered\UncoveredPlacesCount;
use Modules\Shipping\Application\Uncovered\UncoveredRecounts;
use Modules\Shipping\Domain\Repository\CarrierRepository;
use Modules\Shipping\Domain\Repository\PriceGroupRepository;
use Modules\Shipping\Infrastructure\Eloquent\DatabaseCarrierRepository;
use Modules\Shipping\Infrastructure\Eloquent\DatabasePriceGroupRepository;
use Modules\Shipping\Infrastructure\Eloquent\DatabasePriceReader;
use Modules\Shipping\Infrastructure\Eloquent\DatabaseUncoveredCounts;
use Modules\Shipping\Infrastructure\Media\CarrierLogoUsage;
use Modules\Shipping\Infrastructure\Queue\CountUncoveredPlacesJob;
use Modules\Shipping\Infrastructure\Queue\QueuedUncoveredRecounts;
use Modules\Shipping\Presentation\Http\Resource\CarrierPages;
use Modules\Shipping\Public\Contracts\ShippingApi;

/**
 * Shipping (shipping.md): each store's carriers - and, from steps 4 and 5, their prices, free
 * shipping, the quote and the boxes. Backend only; the screens are the screens session's (§4.1).
 */
final class ShippingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CarrierRepository::class, DatabaseCarrierRepository::class);
        $this->app->bind(PriceGroupRepository::class, DatabasePriceGroupRepository::class);
        $this->app->bind(PriceReader::class, DatabasePriceReader::class);
        $this->app->bind(UncoveredCounts::class, DatabaseUncoveredCounts::class);
        $this->app->bind(UncoveredRecounts::class, QueuedUncoveredRecounts::class);
        $this->app->bind(ShippingApi::class, ShippingApiImpl::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Persistence/Migrations');
        $this->loadTranslationsFrom(dirname(__DIR__).'/Presentation/lang', 'shipping');

        $this->app->make(PermissionCatalog::class)->declare('shipping', ...ShippingPermissions::definitions());
        // A ship-to country is taken off a store only once it has no carrier there (§1.3, step 3 Q3).
        $this->app->make(ShipToCountryGuards::class)->register(CarriersHoldCountry::class);
        // A carrier's logo, asked before the media library deletes the file (§1.3).
        $this->app->make(MediaUsages::class)->register('shipping', CarrierLogoUsage::class);
        // The store-wide free-above amount (§1.5), on Platform's settings page.
        $this->app->make(SettingsRegistry::class)->define('shipping', ...ShippingSettings::definitions());

        // "Places no carrier serves" counted again every ten minutes for every store: a place Platform
        // adds or retires, or a ship-to country added, tells Shipping nothing (§1.6, §6).
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->job(new CountUncoveredPlacesJob)->everyTenMinutes()->onOneServer();
        });

        /*
        | The screens (§4.1 part 1). The Shipping menu offers Carriers to anyone who may view or manage
        | them in some store, with the count of places no carrier serves (§1.6). What is offered is never
        | what is allowed: every handler behind it asks again (handoff §19).
        */
        $this->app->make(AdminMenu::class)->register(
            new MenuEntryDto('shipping', 'carriers', 'shipping', 'shipping.admin.carriers', CarrierPages::JOBS, 10, count: UncoveredPlacesCount::class, icon: 'carriers'),
        );

        if (! $this->app->routesAreCached()) {
            // The panel's own file: the admin session cookie must be set before "web" opens a session,
            // so it brings its own middleware rather than sharing the shop's group.
            $this->loadRoutesFrom(dirname(__DIR__).'/Presentation/admin-routes.php');
        }
    }
}
