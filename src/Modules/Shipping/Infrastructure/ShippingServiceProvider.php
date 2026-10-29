<?php

declare(strict_types=1);

namespace Modules\Shipping\Infrastructure;

use Illuminate\Support\ServiceProvider;
use Modules\Access\Public\Contracts\PermissionCatalog;
use Modules\Platform\Public\Contracts\AdminMenu;
use Modules\Platform\Public\Contracts\MediaUsages;
use Modules\Platform\Public\Contracts\ShipToCountryGuards;
use Modules\Platform\Public\Dto\MenuEntryDto;
use Modules\Shipping\Application\Carrier\CarriersHoldCountry;
use Modules\Shipping\Application\ShippingPermissions;
use Modules\Shipping\Domain\Repository\CarrierRepository;
use Modules\Shipping\Infrastructure\Eloquent\DatabaseCarrierRepository;
use Modules\Shipping\Infrastructure\Media\CarrierLogoUsage;
use Modules\Shipping\Presentation\Http\Resource\CarrierPages;

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

        /*
        | The screens (§4.1 part 1). The Shipping menu offers Carriers to anyone who may view or manage
        | them in some store. What is offered is never what is allowed: every handler behind it asks again
        | (handoff §19).
        */
        $this->app->make(AdminMenu::class)->register(
            new MenuEntryDto('shipping', 'carriers', 'shipping', 'shipping.admin.carriers', CarrierPages::JOBS, 10, icon: 'carriers'),
        );

        if (! $this->app->routesAreCached()) {
            // The panel's own file: the admin session cookie must be set before "web" opens a session,
            // so it brings its own middleware rather than sharing the shop's group.
            $this->loadRoutesFrom(dirname(__DIR__).'/Presentation/admin-routes.php');
        }
    }
}
