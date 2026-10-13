<?php

declare(strict_types=1);

namespace Modules\Loyalty\Infrastructure;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Modules\Access\Public\Contracts\PermissionCatalog;
use Modules\Loyalty\Application\LoyaltyApiImpl;
use Modules\Loyalty\Application\LoyaltyPermissions;
use Modules\Loyalty\Application\Settings\ProgrammeSettings;
use Modules\Loyalty\Domain\Repository\PointsAccounts;
use Modules\Loyalty\Domain\Repository\PointsLedger;
use Modules\Loyalty\Domain\Repository\PointsSettlements;
use Modules\Loyalty\Infrastructure\Eloquent\DatabasePointsAccounts;
use Modules\Loyalty\Infrastructure\Eloquent\DatabasePointsLedger;
use Modules\Loyalty\Infrastructure\Eloquent\DatabasePointsSettlements;
use Modules\Loyalty\Infrastructure\Queue\ExpirePointsJob;
use Modules\Loyalty\Public\Contracts\LoyaltyApi;
use Modules\Platform\Public\Contracts\SettingsRegistry;

/**
 * Points (loyalty.md). Registered after Access: Loyalty declares its permissions through Access's
 * public catalog, and depends on Platform and Access only (handoff §4.4) — Sales calls it, never the
 * other way round.
 */
final class LoyaltyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PointsAccounts::class, DatabasePointsAccounts::class);
        $this->app->bind(PointsLedger::class, DatabasePointsLedger::class);
        $this->app->bind(PointsSettlements::class, DatabasePointsSettlements::class);
        $this->app->bind(LoyaltyApi::class, LoyaltyApiImpl::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Persistence/Migrations');
        $this->loadTranslationsFrom(dirname(__DIR__).'/Presentation/lang', 'loyalty');

        $this->app->make(PermissionCatalog::class)->declare('loyalty', ...LoyaltyPermissions::definitions());

        // Each store's points programme, on Platform's settings page under Points (§1.5).
        $this->app->make(SettingsRegistry::class)->define('loyalty', ...ProgrammeSettings::definitions());

        // The nightly expiry (§1.10), at 00:30 UTC — after Access's 00:00 sweep. Scheduled work is
        // queued as a job, never run in the scheduler's own process.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->job(ExpirePointsJob::class)->dailyAt('00:30')->onOneServer();
        });
    }
}
