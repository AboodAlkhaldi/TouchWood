<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Modules\Access\Public\Contracts\PermissionCatalog;
use Modules\Access\Public\Events\CustomerAnonymized;
use Modules\Access\Public\Events\CustomerBlocked;
use Modules\Access\Public\Events\CustomerDeletionCancelled;
use Modules\Access\Public\Events\CustomerDeletionScheduled;
use Modules\Access\Public\Events\CustomerEmailVerified;
use Modules\Access\Public\Events\CustomerLocaleChanged;
use Modules\Access\Public\Events\CustomerUnblocked;
use Modules\Access\Public\Events\MarketingConsentChanged;
use Modules\Ops\Application\Delivery\BroadcastQueue;
use Modules\Ops\Application\Inbox\InboxQueries;
use Modules\Ops\Application\Messages\EmailSender;
use Modules\Ops\Application\Messages\NewsletterList;
use Modules\Ops\Application\Messages\SmsSender;
use Modules\Ops\Application\Newsletter\NewsletterQueue;
use Modules\Ops\Application\OpsPermissions;
use Modules\Ops\Domain\Repository\Inbox;
use Modules\Ops\Domain\Repository\Notices;
use Modules\Ops\Domain\Repository\OpsLog;
use Modules\Ops\Domain\Repository\SentMessages;
use Modules\Ops\Domain\Repository\StaffMessages;
use Modules\Ops\Domain\Repository\Subscribers;
use Modules\Ops\Infrastructure\Delivery\QueuedBroadcasts;
use Modules\Ops\Infrastructure\Eloquent\DatabaseInbox;
use Modules\Ops\Infrastructure\Eloquent\DatabaseInboxQueries;
use Modules\Ops\Infrastructure\Eloquent\DatabaseNotices;
use Modules\Ops\Infrastructure\Eloquent\DatabaseOpsLog;
use Modules\Ops\Infrastructure\Eloquent\DatabaseSentMessages;
use Modules\Ops\Infrastructure\Eloquent\DatabaseStaffMessages;
use Modules\Ops\Infrastructure\Eloquent\DatabaseSubscribers;
use Modules\Ops\Infrastructure\External\BrevoEmailSender;
use Modules\Ops\Infrastructure\External\BrevoNewsletterList;
use Modules\Ops\Infrastructure\External\LogEmailSender;
use Modules\Ops\Infrastructure\External\LogNewsletterList;
use Modules\Ops\Infrastructure\External\LogSmsSender;
use Modules\Ops\Infrastructure\Listener\ForgetAnonymizedCustomer;
use Modules\Ops\Infrastructure\Listener\RefreshNewsletterSubscription;
use Modules\Ops\Infrastructure\Newsletter\QueuedNewsletter;
use Modules\Ops\Infrastructure\Queue\PruneOpsLogJob;
use Modules\Ops\Infrastructure\Queue\PruneWrittenMessagesJob;
use Modules\Ops\Infrastructure\Queue\StartDueNoticesJob;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Contracts\ReservedPaths;

/**
 * Ops (ops.md). Registered last: it reads every module's public surface and declares its permissions
 * through Access's public catalog; nothing depends on it (handoff §4.4).
 *
 * Step 2 (the foundation): the schema and its three tables, the permissions, the adapters, the bells
 * and the nightly removal. Step 7: offer notices, staff messages, the newsletter's subscribers and
 * Brevo's lists, and Access's consent, locale and anonymizing events.
 */
final class OpsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Brevo's webhook lives at /newsletter/brevo: Platform builds the {store} pattern at boot.
        $this->app->make(ReservedPaths::class)->reserve('ops', 'newsletter');

        $this->app->bind(Inbox::class, DatabaseInbox::class);
        $this->app->bind(InboxQueries::class, DatabaseInboxQueries::class);
        $this->app->bind(SentMessages::class, DatabaseSentMessages::class);
        $this->app->bind(OpsLog::class, DatabaseOpsLog::class);
        $this->app->bind(Notices::class, DatabaseNotices::class);
        $this->app->bind(StaffMessages::class, DatabaseStaffMessages::class);
        $this->app->bind(Subscribers::class, DatabaseSubscribers::class);
        $this->app->bind(BroadcastQueue::class, QueuedBroadcasts::class);
        $this->app->bind(NewsletterQueue::class, QueuedNewsletter::class);

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
        $this->app->bind(NewsletterList::class, fn (Application $app): NewsletterList => match ($app->make('config')->get('ops.newsletter.driver')) {
            'brevo' => new BrevoNewsletterList(
                $app->make(Factory::class),
                $app->make(ConnectionInterface::class),
                $app->make(PlatformApi::class),
                (string) $app->make('config')->get('services.brevo.key'),
                self::folderId($app->make('config')->get('services.brevo.newsletter_folder')),
            ),
            'log' => $app->environment('production')
                ? throw new InvalidArgumentException('The "log" newsletter driver writes the lists to the log and never runs in production: set OPS_NEWSLETTER_DRIVER to "brevo".')
                : $app->make(LogNewsletterList::class),
            default => throw new InvalidArgumentException('OPS_NEWSLETTER_DRIVER names no newsletter driver Ops has: "brevo" or "log".'),
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Persistence/Migrations');
        $this->loadTranslationsFrom(dirname(__DIR__).'/Presentation/lang', 'ops');
        $this->loadViewsFrom(dirname(__DIR__).'/Presentation/views', 'ops');

        $this->app->make(PermissionCatalog::class)->declare('ops', ...OpsPermissions::definitions());

        // Access's events (§6): each listener on the queue, once per event.
        foreach ([MarketingConsentChanged::class, CustomerLocaleChanged::class, CustomerEmailVerified::class, CustomerBlocked::class, CustomerUnblocked::class, CustomerDeletionScheduled::class, CustomerDeletionCancelled::class] as $event) {
            Event::listen($event, [RefreshNewsletterSubscription::class, 'handle']);
        }

        Event::listen(CustomerAnonymized::class, [ForgetAnonymizedCustomer::class, 'handle']);

        // Inbox entries, sent messages and handled events keep twelve months (§5). Scheduled work is
        // queued as a job, never run in the scheduler's own process.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->job(PruneOpsLogJob::class)->timezone('Asia/Riyadh')->dailyAt('04:30')->onOneServer();
            // Staff messages and old notices after twelve months, unconfirmed sign-ups after seven days.
            $schedule->job(PruneWrittenMessagesJob::class)->timezone('Asia/Riyadh')->dailyAt('04:40')->onOneServer();
            // A scheduled notice starts within a minute of its time (§1.7).
            $schedule->job(StartDueNoticesJob::class)->everyMinute()->onOneServer();
        });

        if (! $this->app->routesAreCached()) {
            $this->loadRoutesFrom(dirname(__DIR__).'/Presentation/shop-routes.php');
            // The panel's own file: the admin session cookie must be set before "web" opens a session.
            $this->loadRoutesFrom(dirname(__DIR__).'/Presentation/admin-routes.php');
            // Brevo's webhook: machine to machine, no session.
            $this->loadRoutesFrom(dirname(__DIR__).'/Presentation/webhook-routes.php');
        }
    }

    /** The Brevo folder the newsletter's lists are made in: a whole number above 0, else none. */
    private static function folderId(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
