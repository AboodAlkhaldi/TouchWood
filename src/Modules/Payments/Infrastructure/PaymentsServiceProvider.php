<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Modules\Access\Public\Contracts\PermissionCatalog;
use Modules\Payments\Application\Gateway\GatewayAddresses;
use Modules\Payments\Application\Gateway\GatewayClient;
use Modules\Payments\Application\Gateway\KeyTester;
use Modules\Payments\Application\Gateway\NoticeReader;
use Modules\Payments\Application\Gateway\OwnAddresses;
use Modules\Payments\Application\PaymentsApiImpl;
use Modules\Payments\Application\PaymentsPermissions;
use Modules\Payments\Application\PaymentsSettings;
use Modules\Payments\Application\Settings\BankAccountSettings;
use Modules\Payments\Domain\Repository\GatewayCalls;
use Modules\Payments\Domain\Repository\Gateways;
use Modules\Payments\Domain\Repository\Notices;
use Modules\Payments\Domain\Repository\Sessions;
use Modules\Payments\Infrastructure\Eloquent\DatabaseGatewayCalls;
use Modules\Payments\Infrastructure\Eloquent\DatabaseGateways;
use Modules\Payments\Infrastructure\Eloquent\DatabaseNotices;
use Modules\Payments\Infrastructure\Eloquent\DatabaseSessions;
use Modules\Payments\Infrastructure\External\AppOwnAddresses;
use Modules\Payments\Infrastructure\External\MyFatoorahAddresses;
use Modules\Payments\Infrastructure\External\MyFatoorahClient;
use Modules\Payments\Infrastructure\External\MyFatoorahKeyTester;
use Modules\Payments\Infrastructure\External\MyFatoorahNoticeReader;
use Modules\Payments\Infrastructure\Queue\WarnExpiringKeysJob;
use Modules\Payments\Infrastructure\Settings\PaymentsSettingsLine;
use Modules\Payments\Public\Contracts\PaymentsApi;
use Modules\Platform\Public\Contracts\SettingGuards;
use Modules\Platform\Public\Contracts\SettingsRegistry;
use Modules\Platform\Public\Contracts\SettingsSectionLines;

/**
 * Payments (payments.md): each store's gateway - MyFatoorah - and its keys, and the timers of a payment;
 * the session and the notices; from steps 10-12, the charge and the requests. Backend only; the screens are
 * the screens session's (§4.1).
 */
final class PaymentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(Gateways::class, DatabaseGateways::class);
        $this->app->bind(KeyTester::class, MyFatoorahKeyTester::class);
        $this->app->bind(GatewayAddresses::class, MyFatoorahAddresses::class);
        $this->app->bind(GatewayClient::class, MyFatoorahClient::class);
        $this->app->bind(NoticeReader::class, MyFatoorahNoticeReader::class);
        $this->app->bind(OwnAddresses::class, AppOwnAddresses::class);
        $this->app->bind(Sessions::class, DatabaseSessions::class);
        $this->app->bind(GatewayCalls::class, DatabaseGatewayCalls::class);
        $this->app->bind(Notices::class, DatabaseNotices::class);
        $this->app->bind(PaymentsApi::class, PaymentsApiImpl::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Persistence/Migrations');
        $this->loadTranslationsFrom(dirname(__DIR__).'/Presentation/lang', 'payments');

        $catalog = $this->app->make(PermissionCatalog::class);
        $catalog->declare('payments', ...PaymentsPermissions::definitions());
        // Given once, then each its own box (gap 8.4; b2b.md amendment 33): whoever can view orders sees
        // the payments, and whoever could change the bank account under B2B's job still can.
        $catalog->copied('payments', 'sales.order.view', PaymentsPermissions::VIEW);
        $catalog->copied('payments', 'b2b.settings.update', PaymentsPermissions::BANK_ACCOUNT_UPDATE);

        $this->app->make(SettingsRegistry::class)->define('payments', ...PaymentsSettings::definitions());
        $this->app->make(SettingsRegistry::class)->define('payments', ...BankAccountSettings::definitions());
        // Whether the store takes online payment and bank transfers, in one line (§1.2, gap 8.8).
        $this->app->make(SettingsSectionLines::class)->register('payments', PaymentsSettingsLine::class);

        // The hold limit stays longer than the window and the wait together (§1.7, gap 8.3).
        foreach ([PaymentsSettings::WINDOW_MINUTES, PaymentsSettings::WAIT_MINUTES, PaymentsSettings::HOLD_MINUTES] as $timer) {
            $this->app->make(SettingGuards::class)->register($timer, TimersGuard::class);
        }

        if (! $this->app->routesAreCached()) {
            $this->loadRoutesFrom(dirname(__DIR__).'/Presentation/notice-routes.php');
        }

        // The keys' expiry warnings (§1.2): hourly, as each store's day starts at its own midnight.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->job(new WarnExpiringKeysJob)->hourly()->onOneServer();
        });
    }
}
