<?php

declare(strict_types=1);

namespace Modules\Loyalty\Infrastructure;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Modules\Access\Public\Contracts\CustomerAccountPages;
use Modules\Access\Public\Contracts\CustomerPageSections;
use Modules\Access\Public\Contracts\PermissionCatalog;
use Modules\Access\Public\Dto\CustomerAccountPageDto;
use Modules\Access\Public\Dto\CustomerPageSectionDto;
use Modules\Loyalty\Application\LoyaltyApiImpl;
use Modules\Loyalty\Application\LoyaltyPermissions;
use Modules\Loyalty\Application\Points\PointsTabOffered;
use Modules\Loyalty\Application\Query\PointsReader;
use Modules\Loyalty\Application\Settings\ProgrammeSettings;
use Modules\Loyalty\Domain\Repository\PointsAccounts;
use Modules\Loyalty\Domain\Repository\PointsLedger;
use Modules\Loyalty\Domain\Repository\PointsSettlements;
use Modules\Loyalty\Domain\Repository\RedemptionHolds;
use Modules\Loyalty\Infrastructure\Eloquent\DatabasePointsAccounts;
use Modules\Loyalty\Infrastructure\Eloquent\DatabasePointsLedger;
use Modules\Loyalty\Infrastructure\Eloquent\DatabasePointsReader;
use Modules\Loyalty\Infrastructure\Eloquent\DatabasePointsSettlements;
use Modules\Loyalty\Infrastructure\Eloquent\DatabaseRedemptionHolds;
use Modules\Loyalty\Infrastructure\Queue\ExpirePointsJob;
use Modules\Loyalty\Presentation\Http\Resource\PointsCustomerSection;
use Modules\Loyalty\Public\Contracts\LoyaltyApi;
use Modules\Platform\Public\Contracts\AdminMenu;
use Modules\Platform\Public\Contracts\SettingsRegistry;
use Modules\Platform\Public\Dto\MenuEntryDto;

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
        $this->app->bind(RedemptionHolds::class, DatabaseRedemptionHolds::class);
        $this->app->bind(PointsSettlements::class, DatabasePointsSettlements::class);
        $this->app->bind(PointsReader::class, DatabasePointsReader::class);
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

        /*
        | The screens (§4.1). The Points menu: its holders and redemptions for anyone who may read a
        | store's points. Its settings are reached only from Store Settings (owner, 2026-10-19). What is
        | offered is never what is allowed: every handler behind these asks again (handoff §19).
        */
        $this->app->make(AdminMenu::class)->register(
            new MenuEntryDto('loyalty', 'customers', 'points', 'loyalty.admin.customers', LoyaltyPermissions::VIEW, 10, icon: 'customers'),
            new MenuEntryDto('loyalty', 'redemptions', 'points', 'loyalty.admin.redemptions', LoyaltyPermissions::VIEW, 20, icon: 'redemptions'),
        );

        // A customer's points on the staff customer page, under the addresses (access.md amendment
        // 72(a)), and the account's Points tab in the shop while it is offered (72(d)).
        $this->app->make(CustomerPageSections::class)->register(
            new CustomerPageSectionDto('loyalty', 'points', PointsCustomerSection::class, ['loyalty::admin_points']),
        );
        $this->app->make(CustomerAccountPages::class)->register(
            new CustomerAccountPageDto('loyalty', 'points', 'storefront.loyalty.points', condition: PointsTabOffered::class),
        );

        if (! $this->app->routesAreCached()) {
            $this->loadRoutesFrom(dirname(__DIR__).'/Presentation/shop-routes.php');
            // The panel's own file, apart: the admin session cookie must be set before "web" opens a
            // session, so it brings its own middleware rather than sharing the shop's group.
            $this->loadRoutesFrom(dirname(__DIR__).'/Presentation/admin-routes.php');
        }
    }
}
