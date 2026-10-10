<?php

declare(strict_types=1);

namespace Modules\Pricing\Infrastructure;

use Illuminate\Support\ServiceProvider;
use Modules\Access\Public\Contracts\PermissionCatalog;
use Modules\Pricing\Application\PricingPermissions;

/**
 * What a size costs, and every amount of an order (pricing.md). Registered after Catalog, whose
 * variants it prices, and after Access, whose public surface it uses to declare its permissions and
 * for nothing else (handoff §4.4; pricing.md §2.4).
 *
 * Step 1 (the foundation): the schema, the permissions, the errors and their words. `PricingApi` is
 * bound with the step that implements it.
 */
final class PricingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Persistence/Migrations');
        $this->loadTranslationsFrom(dirname(__DIR__).'/Presentation/lang', 'pricing');

        // Pricing sits above Access, so it declares its own permissions through Access's public
        // catalog (Access spec §2.2); Access checks them at boot like its own.
        $this->app->make(PermissionCatalog::class)->declare('pricing', ...PricingPermissions::definitions());
    }
}
