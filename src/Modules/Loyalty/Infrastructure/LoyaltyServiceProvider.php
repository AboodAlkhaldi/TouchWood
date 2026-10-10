<?php

declare(strict_types=1);

namespace Modules\Loyalty\Infrastructure;

use Illuminate\Support\ServiceProvider;
use Modules\Access\Public\Contracts\PermissionCatalog;
use Modules\Loyalty\Application\LoyaltyPermissions;
use Modules\Loyalty\Application\Settings\ProgrammeSettings;
use Modules\Platform\Public\Contracts\SettingsRegistry;

/**
 * Points (loyalty.md). Registered after Access: Loyalty declares its permissions through Access's
 * public catalog, and depends on Platform and Access only (handoff §4.4) — Sales calls it, never the
 * other way round.
 */
final class LoyaltyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Persistence/Migrations');
        $this->loadTranslationsFrom(dirname(__DIR__).'/Presentation/lang', 'loyalty');

        $this->app->make(PermissionCatalog::class)->declare('loyalty', ...LoyaltyPermissions::definitions());

        // Each store's points programme, on Platform's settings page under Points (§1.5).
        $this->app->make(SettingsRegistry::class)->define('loyalty', ...ProgrammeSettings::definitions());
    }
}
