<?php

declare(strict_types=1);

namespace Modules\Payments\Infrastructure;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use Modules\Access\Public\Contracts\PermissionCatalog;
use Modules\Payments\Application\Gateway\GatewayAddresses;
use Modules\Payments\Application\Gateway\KeyTester;
use Modules\Payments\Application\PaymentsApiImpl;
use Modules\Payments\Application\PaymentsPermissions;
use Modules\Payments\Application\PaymentsSettings;
use Modules\Payments\Domain\Repository\Gateways;
use Modules\Payments\Infrastructure\Eloquent\DatabaseGateways;
use Modules\Payments\Infrastructure\External\MyFatoorahAddresses;
use Modules\Payments\Infrastructure\External\MyFatoorahKeyTester;
use Modules\Payments\Infrastructure\Queue\WarnExpiringKeysJob;
use Modules\Payments\Public\Contracts\PaymentsApi;
use Modules\Platform\Public\Contracts\SettingGuards;
use Modules\Platform\Public\Contracts\SettingsRegistry;

/**
 * Payments (payments.md): each store's gateway - MyFatoorah - and its keys, and the timers of a payment;
 * from steps 9-11, the session, the charge, the notices and the requests. Backend only; the screens are
 * the screens session's (§4.1).
 */
final class PaymentsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(Gateways::class, DatabaseGateways::class);
        $this->app->bind(KeyTester::class, MyFatoorahKeyTester::class);
        $this->app->bind(GatewayAddresses::class, MyFatoorahAddresses::class);
        $this->app->bind(PaymentsApi::class, PaymentsApiImpl::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Persistence/Migrations');
        $this->loadTranslationsFrom(dirname(__DIR__).'/Presentation/lang', 'payments');

        $this->app->make(PermissionCatalog::class)->declare('payments', ...PaymentsPermissions::definitions());
        $this->app->make(SettingsRegistry::class)->define('payments', ...PaymentsSettings::definitions());

        // The hold limit stays longer than the window and the wait together (§1.7, gap 8.3).
        foreach ([PaymentsSettings::WINDOW_MINUTES, PaymentsSettings::WAIT_MINUTES, PaymentsSettings::HOLD_MINUTES] as $timer) {
            $this->app->make(SettingGuards::class)->register($timer, TimersGuard::class);
        }

        // The keys' expiry warnings (§1.2): hourly, as each store's day starts at its own midnight.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->job(new WarnExpiringKeysJob)->hourly()->onOneServer();
        });
    }
}
