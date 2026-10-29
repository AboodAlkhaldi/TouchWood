<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Modules\Access\Public\Contracts\PermissionCatalog;
use Modules\Ops\Application\Inbox\InboxQueries;
use Modules\Ops\Application\Messages\EmailSender;
use Modules\Ops\Application\Messages\SmsSender;
use Modules\Ops\Application\OpsPermissions;
use Modules\Ops\Domain\Repository\Inbox;
use Modules\Ops\Domain\Repository\OpsLog;
use Modules\Ops\Domain\Repository\SentMessages;
use Modules\Ops\Infrastructure\Eloquent\DatabaseInbox;
use Modules\Ops\Infrastructure\Eloquent\DatabaseInboxQueries;
use Modules\Ops\Infrastructure\Eloquent\DatabaseOpsLog;
use Modules\Ops\Infrastructure\Eloquent\DatabaseSentMessages;
use Modules\Ops\Infrastructure\External\BrevoEmailSender;
use Modules\Ops\Infrastructure\External\LogEmailSender;
use Modules\Ops\Infrastructure\External\LogSmsSender;
use Modules\Ops\Infrastructure\Queue\PruneOpsLogJob;

/**
 * Ops (ops.md). Registered last: it reads every module's public surface and declares its permissions
 * through Access's public catalog; nothing depends on it (handoff §4.4).
 *
 * Step 2 (the foundation): the schema and its three tables, the permissions, the adapters, the bells
 * and the nightly removal. No message is sent yet: the listeners come with the later steps.
 */
final class OpsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(Inbox::class, DatabaseInbox::class);
        $this->app->bind(InboxQueries::class, DatabaseInboxQueries::class);
        $this->app->bind(SentMessages::class, DatabaseSentMessages::class);
        $this->app->bind(OpsLog::class, DatabaseOpsLog::class);

        // The providers are adapters chosen by configuration (§2.2): a new one is a new class and a
        // line here. "log" writes what would be sent to the log, never on a live system.
        $this->app->bind(EmailSender::class, fn (Application $app): EmailSender => match ($app->make('config')->get('ops.email.driver')) {
            'brevo' => new BrevoEmailSender($app->make(Factory::class), (string) $app->make('config')->get('services.brevo.key')),
            'log' => $app->environment('production')
                ? throw new InvalidArgumentException('The "log" email driver writes emails to the log and never runs in production: set OPS_EMAIL_DRIVER to "brevo".')
                : $app->make(LogEmailSender::class),
            default => throw new InvalidArgumentException('OPS_EMAIL_DRIVER names no email driver Ops has: "brevo" or "log".'),
        });
        $this->app->bind(SmsSender::class, fn (Application $app): SmsSender => match ($app->make('config')->get('ops.sms.driver')) {
            'log' => $app->environment('production')
                ? throw new InvalidArgumentException('The "log" SMS driver writes messages to the log and never runs in production: set OPS_SMS_DRIVER to a real provider.')
                : $app->make(LogSmsSender::class),
            default => throw new InvalidArgumentException('OPS_SMS_DRIVER names no SMS driver Ops has; only "log" exists until the provider is chosen.'),
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Persistence/Migrations');
        $this->loadTranslationsFrom(dirname(__DIR__).'/Presentation/lang', 'ops');

        $this->app->make(PermissionCatalog::class)->declare('ops', ...OpsPermissions::definitions());

        // Inbox entries, sent messages and handled events keep twelve months (§5). Scheduled work is
        // queued as a job, never run in the scheduler's own process.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->job(PruneOpsLogJob::class)->timezone('Asia/Riyadh')->dailyAt('04:30')->onOneServer();
        });

        if (! $this->app->routesAreCached()) {
            $this->loadRoutesFrom(dirname(__DIR__).'/Presentation/shop-routes.php');
            // The panel's own file: the admin session cookie must be set before "web" opens a session.
            $this->loadRoutesFrom(dirname(__DIR__).'/Presentation/admin-routes.php');
        }
    }
}
