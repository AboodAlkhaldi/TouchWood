<?php

declare(strict_types=1);

namespace Modules\Sales\Infrastructure;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Access\Public\Contracts\PermissionCatalog;
use Modules\Access\Public\Events\CustomerAnonymized;
use Modules\Access\Public\Events\GuestBecameCustomer;
use Modules\Inventory\Public\Events\HoldsShort;
use Modules\Platform\Public\Contracts\MediaUsages;
use Modules\Platform\Public\Contracts\SettingsRegistry;
use Modules\Platform\Public\Events\StoreCreated;
use Modules\Sales\Application\Query\Own\OwnReads;
use Modules\Sales\Application\Query\Staff\StaffReads;
use Modules\Sales\Application\Query\Staff\StaffReturnReads;
use Modules\Sales\Application\SalesPermissions;
use Modules\Sales\Application\SalesSettings;
use Modules\Sales\Domain\Repository\Carts;
use Modules\Sales\Domain\Repository\MoneyRecords;
use Modules\Sales\Domain\Repository\OrderNumbers;
use Modules\Sales\Domain\Repository\Orders;
use Modules\Sales\Domain\Repository\Quotes;
use Modules\Sales\Domain\Repository\Returns;
use Modules\Sales\Infrastructure\Eloquent\DatabaseCarts;
use Modules\Sales\Infrastructure\Eloquent\DatabaseMoneyRecords;
use Modules\Sales\Infrastructure\Eloquent\DatabaseOrders;
use Modules\Sales\Infrastructure\Eloquent\DatabaseOwnReads;
use Modules\Sales\Infrastructure\Eloquent\DatabaseQuotes;
use Modules\Sales\Infrastructure\Eloquent\DatabaseReturns;
use Modules\Sales\Infrastructure\Eloquent\DatabaseSalesApi;
use Modules\Sales\Infrastructure\Eloquent\DatabaseStaffReads;
use Modules\Sales\Infrastructure\Eloquent\DatabaseStaffReturnReads;
use Modules\Sales\Infrastructure\Eloquent\OrderNumberSequences;
use Modules\Sales\Infrastructure\Listener\CreateOrderSequence;
use Modules\Sales\Infrastructure\Listener\FlagShortOrders;
use Modules\Sales\Infrastructure\Listener\ForgetCustomer;
use Modules\Sales\Infrastructure\Listener\MoveGuestCart;
use Modules\Sales\Infrastructure\Media\SalesFilesUsage;
use Modules\Sales\Infrastructure\Queue\DeleteExpiredQuotesJob;
use Modules\Sales\Infrastructure\Queue\EmptyOldCartsJob;
use Modules\Sales\Infrastructure\Queue\PushSalesRanksJob;
use Modules\Sales\Public\Contracts\SalesApi;

/**
 * Sales (sales.md). Registered after Access and Platform: it declares its permissions through
 * Access's public catalog and its settings through Platform. Its public contract (§2.1) reads its own
 * deliveries and completed returns.
 */
final class SalesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SalesApi::class, DatabaseSalesApi::class);
        $this->app->bind(Carts::class, DatabaseCarts::class);
        $this->app->bind(Quotes::class, DatabaseQuotes::class);
        $this->app->bind(OrderNumbers::class, OrderNumberSequences::class);
        $this->app->bind(Orders::class, DatabaseOrders::class);
        $this->app->bind(Returns::class, DatabaseReturns::class);
        $this->app->bind(MoneyRecords::class, DatabaseMoneyRecords::class);
        $this->app->bind(OwnReads::class, DatabaseOwnReads::class);
        $this->app->bind(StaffReads::class, DatabaseStaffReads::class);
        $this->app->bind(StaffReturnReads::class, DatabaseStaffReturnReads::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Persistence/Migrations');
        $this->loadTranslationsFrom(dirname(__DIR__).'/Presentation/lang', 'sales');

        $this->app->make(PermissionCatalog::class)->declare('sales', ...SalesPermissions::definitions());
        // The flat shipping fee, free shipping from and the return window (§1.10), on Platform's settings page.
        $this->app->make(SettingsRegistry::class)->define('sales', ...SalesSettings::definitions());
        // A bank-transfer document an order holds, and a photo a return holds, are never deleted (§1.8, §1.9, §5).
        $this->app->make(MediaUsages::class)->register('sales', SalesFilesUsage::class);

        // A guest signed in or registered: their carts move or merge, what the account may not buy leaving (§1.1).
        Event::listen(GuestBecameCustomer::class, [MoveGuestCart::class, 'handle']);
        // An anonymized account's carts deleted (§1.12).
        Event::listen(CustomerAnonymized::class, [ForgetCustomer::class, 'handle']);
        // A store opened later gets its own order numbers from 1001 (§1.3).
        Event::listen(StoreCreated::class, [CreateOrderSequence::class, 'handle']);
        // Stock removed under placed orders: those still open and unshipped flagged "stock was reduced" (§1.11).
        Event::listen(HoldsShort::class, [FlagShortOrders::class, 'handle']);

        // Carts no one touched for 90 days, emptied each night (§1.1). Scheduled work is queued as a
        // job, never command() or call() (owner's decision, 2026-09-18).
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->job(EmptyOldCartsJob::class)->timezone('Asia/Riyadh')->dailyAt('03:15')->onOneServer();
            // Quotes past their expiry (§5).
            $schedule->job(DeleteExpiredQuotesJob::class)->timezone('Asia/Riyadh')->dailyAt('03:30')->onOneServer();
            // The shop's Best-Selling ranks (§1.13).
            $schedule->job(PushSalesRanksJob::class)->timezone('Asia/Riyadh')->dailyAt('03:45')->onOneServer();
        });
    }
}
