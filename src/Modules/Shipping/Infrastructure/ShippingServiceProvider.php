<?php

declare(strict_types=1);

namespace Modules\Shipping\Infrastructure;

use Illuminate\Support\ServiceProvider;
use Modules\Access\Public\Contracts\PermissionCatalog;
use Modules\Platform\Public\Contracts\MediaUsages;
use Modules\Platform\Public\Contracts\ShipToCountryGuards;
use Modules\Shipping\Application\Carrier\CarriersHoldCountry;
use Modules\Shipping\Application\ShippingPermissions;
use Modules\Shipping\Domain\Repository\CarrierRepository;
use Modules\Shipping\Infrastructure\Eloquent\DatabaseCarrierRepository;
use Modules\Shipping\Infrastructure\Media\CarrierLogoUsage;

/**
 * Shipping (shipping.md): each store's carriers - and, from steps 4 and 5, their prices, free
 * shipping, the quote and the boxes. Backend only; the screens are the screens session's (§4.1).
 */
final class ShippingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CarrierRepository::class, DatabaseCarrierRepository::class);
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
    }
}
