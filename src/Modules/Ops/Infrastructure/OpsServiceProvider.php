<?php

declare(strict_types=1);

namespace Modules\Ops\Infrastructure;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Modules\Access\Public\Contracts\CustomerAccountPages;
use Modules\Access\Public\Contracts\PermissionCatalog;
use Modules\Access\Public\Contracts\PhoneCountries;
use Modules\Access\Public\Contracts\SecurityMessages;
use Modules\Access\Public\Dto\CustomerAccountPageDto;
use Modules\Access\Public\Events\CustomerAnonymized;
use Modules\Access\Public\Events\CustomerBlocked;
use Modules\Access\Public\Events\CustomerDeletionCancelled;
use Modules\Access\Public\Events\CustomerDeletionScheduled;
use Modules\Access\Public\Events\CustomerEmailVerified;
use Modules\Access\Public\Events\CustomerLocaleChanged;
use Modules\Access\Public\Events\CustomerRegistered;
use Modules\Access\Public\Events\CustomerUnblocked;
use Modules\Access\Public\Events\GuestBecameCustomer;
use Modules\Access\Public\Events\MarketingConsentChanged;
use Modules\B2B\Public\Events\CompanyApplicationSubmitted;
use Modules\Content\Public\Contracts\CampaignNotices;
use Modules\Content\Public\Events\CampaignExpiring;
use Modules\Content\Public\Events\CampaignWentLive;
use Modules\Feedback\Public\Events\QuestionAnswered;
use Modules\Feedback\Public\Events\QuestionNotPublished;
use Modules\Feedback\Public\Events\ReviewNotPublished;
use Modules\Inventory\Public\Events\LowStockChanged;
use Modules\Loyalty\Public\Events\PointsChangedByHand;
use Modules\Loyalty\Public\Events\PointsEarned;
use Modules\Ops\Application\Alerts\EventDeliveries;
use Modules\Ops\Application\DailyReport\DailyReportEmailQueue;
use Modules\Ops\Application\DailyReport\DailyReportMailer;
use Modules\Ops\Application\DailyReport\DailyReportRenderer;
use Modules\Ops\Application\DailyReport\ReportFiles;
use Modules\Ops\Application\Delivery\BroadcastQueue;
use Modules\Ops\Application\Inbox\InboxQueries;
use Modules\Ops\Application\Messages\CodeSender;
use Modules\Ops\Application\Messages\EmailSender;
use Modules\Ops\Application\Messages\ListedPhoneCountries;
use Modules\Ops\Application\Messages\NewsletterList;
use Modules\Ops\Application\Messages\SmsSenders;
use Modules\Ops\Application\Newsletter\CustomerSubscriptions;
use Modules\Ops\Application\Newsletter\NewsletterQueue;
use Modules\Ops\Application\OpsPermissions;
use Modules\Ops\Application\Query\ListStaffMessages\StaffMessageQueries;
use Modules\Ops\Application\Reports\ReportWriter;
use Modules\Ops\Domain\Repository\AutoReplies;
use Modules\Ops\Domain\Repository\DailyReports;
use Modules\Ops\Domain\Repository\Inbox;
use Modules\Ops\Domain\Repository\Notices;
use Modules\Ops\Domain\Repository\OpsLog;
use Modules\Ops\Domain\Repository\SentMessages;
use Modules\Ops\Domain\Repository\SmsCountries;
use Modules\Ops\Domain\Repository\StaffMessages;
use Modules\Ops\Domain\Repository\Subscribers;
use Modules\Ops\Infrastructure\Delivery\QueuedBroadcasts;
use Modules\Ops\Infrastructure\Eloquent\DatabaseAutoReplies;
use Modules\Ops\Infrastructure\Eloquent\DatabaseDailyReports;
use Modules\Ops\Infrastructure\Eloquent\DatabaseInbox;
use Modules\Ops\Infrastructure\Eloquent\DatabaseInboxQueries;
use Modules\Ops\Infrastructure\Eloquent\DatabaseNotices;
use Modules\Ops\Infrastructure\Eloquent\DatabaseOpsLog;
use Modules\Ops\Infrastructure\Eloquent\DatabaseSentMessages;
use Modules\Ops\Infrastructure\Eloquent\DatabaseSmsCountries;
use Modules\Ops\Infrastructure\Eloquent\DatabaseStaffMessageQueries;
use Modules\Ops\Infrastructure\Eloquent\DatabaseStaffMessages;
use Modules\Ops\Infrastructure\Eloquent\DatabaseSubscribers;
use Modules\Ops\Infrastructure\Excel\OpenSpoutReportWriter;
use Modules\Ops\Infrastructure\External\BrevoEmailSender;
use Modules\Ops\Infrastructure\External\BrevoNewsletterList;
use Modules\Ops\Infrastructure\External\BrevoUnsubscribeRead;
use Modules\Ops\Infrastructure\External\BuiltSmsSenders;
use Modules\Ops\Infrastructure\External\DailyReportEmailMailer;
use Modules\Ops\Infrastructure\External\EmailSetup;
use Modules\Ops\Infrastructure\External\LogEmailSender;
use Modules\Ops\Infrastructure\External\LogNewsletterList;
use Modules\Ops\Infrastructure\External\LogSmsSender;
use Modules\Ops\Infrastructure\External\NoNewsletterList;
use Modules\Ops\Infrastructure\External\NotSetUpEmailSender;
use Modules\Ops\Infrastructure\External\WhatsAppCodeSender;
use Modules\Ops\Infrastructure\Files\LocalReportFiles;
use Modules\Ops\Infrastructure\Listener\AlertStaff;
use Modules\Ops\Infrastructure\Listener\ForgetAnonymizedCustomer;
use Modules\Ops\Infrastructure\Listener\RefreshNewsletterSubscription;
use Modules\Ops\Infrastructure\Listener\StartCampaignNotice;
use Modules\Ops\Infrastructure\Listener\TellCustomerAboutFeedback;
use Modules\Ops\Infrastructure\Listener\TellCustomerAboutMoney;
use Modules\Ops\Infrastructure\Listener\TellCustomerAboutOrder;
use Modules\Ops\Infrastructure\Listener\TellCustomerAboutPaymentRequest;
use Modules\Ops\Infrastructure\Listener\TellCustomerAboutPoints;
use Modules\Ops\Infrastructure\Listener\TellCustomerAboutReturn;
use Modules\Ops\Infrastructure\Listener\TellCustomersAboutCoupon;
use Modules\Ops\Infrastructure\Messages\EmailBanner;
use Modules\Ops\Infrastructure\Messages\OpsCampaignNotices;
use Modules\Ops\Infrastructure\Messages\OpsSecurityMessages;
use Modules\Ops\Infrastructure\Messages\WhatsAppBanner;
use Modules\Ops\Infrastructure\Newsletter\QueuedNewsletter;
use Modules\Ops\Infrastructure\Pdf\MpdfDailyReportRenderer;
use Modules\Ops\Infrastructure\Queue\PruneDailyReportsJob;
use Modules\Ops\Infrastructure\Queue\PruneOpsLogJob;
use Modules\Ops\Infrastructure\Queue\PruneWrittenMessagesJob;
use Modules\Ops\Infrastructure\Queue\QueuedDailyReportEmails;
use Modules\Ops\Infrastructure\Queue\QueuedEventDeliveries;
use Modules\Ops\Infrastructure\Queue\ReadBrevoUnsubscribesJob;
use Modules\Ops\Infrastructure\Queue\RemindExpiringPointsJob;
use Modules\Ops\Infrastructure\Queue\StartDueNoticesJob;
use Modules\Ops\Presentation\Bell\PanelBellPart;
use Modules\Ops\Presentation\Console\BrevoCheckCommand;
use Modules\Ops\Presentation\Frame\ShopPart;
use Modules\Ops\Presentation\Home\TodaysReportCard;
use Modules\Payments\Public\Events\GatewayKeyExpiring;
use Modules\Payments\Public\Events\GatewayKeyRejected;
use Modules\Payments\Public\Events\PaymentNotAccepted;
use Modules\Payments\Public\Events\PaymentRequested;
use Modules\Platform\Public\Contracts\AdminBanners;
use Modules\Platform\Public\Contracts\AdminFrameParts;
use Modules\Platform\Public\Contracts\AdminMenu;
use Modules\Platform\Public\Contracts\HomeCards;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Contracts\ReservedPaths;
use Modules\Platform\Public\Contracts\ShopFrameParts;
use Modules\Platform\Public\Dto\HomeCardDto;
use Modules\Platform\Public\Dto\MenuEntryDto;
use Modules\Platform\Public\Enums\HomeBand;
use Modules\Promotions\Public\Events\CouponAssigned;
use Modules\Sales\Public\Events\LatePaymentRefundDue;
use Modules\Sales\Public\Events\OrderApproved;
use Modules\Sales\Public\Events\OrderCameBack;
use Modules\Sales\Public\Events\OrderCancelled;
use Modules\Sales\Public\Events\OrderDelivered;
use Modules\Sales\Public\Events\OrderEdited;
use Modules\Sales\Public\Events\OrderPlaced;
use Modules\Sales\Public\Events\OrderRejected;
use Modules\Sales\Public\Events\OrderShipped;
use Modules\Sales\Public\Events\PaymentRecorded;
use Modules\Sales\Public\Events\RefundRecorded;
use Modules\Sales\Public\Events\ReturnAccepted;
use Modules\Sales\Public\Events\ReturnCompleted;
use Modules\Sales\Public\Events\ReturnReceived;
use Modules\Sales\Public\Events\ReturnRefused;
use Modules\Sales\Public\Events\ReturnRequested;
use Modules\Sales\Public\Events\TransferOverdue;
use Modules\Sync\Public\Events\SyncNeedsAttention;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Ops (ops.md). Registered last: it reads every module's public surface and declares its permissions
 * through Access's public catalog; nothing depends on it (handoff §4.4).
 *
 * Step 2 (the foundation): the schema and its three tables, the permissions, the adapters, the bells
 * and the nightly removal. Step 3: the security messages (C18) in place of Access's temporary sender,
 * and the SMS countries (access.md amendment 90). Step 7: offer notices, staff messages, the
 * newsletter's subscribers and Brevo's lists, and Access's consent, locale and anonymizing events.
 * Step 8: the reports' Excel writer.
 * Step 9: the daily report - its reader, its PDF, the files kept, the email at the time chosen.
 * Steps 5 and 6: the customer messages and the staff alerts on the other modules' events, and the
 * morning reminder before points expire.
 */
final class OpsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Brevo's webhook lives at /newsletter/brevo: Platform builds the {store} pattern at boot.
        $this->app->make(ReservedPaths::class)->reserve('ops', 'newsletter');
        // Meta's WhatsApp webhook lives at /whatsapp/webhook (ops.md §9.8 point 4), the same way.
        $this->app->make(ReservedPaths::class)->reserve('ops', 'whatsapp');

        $this->app->bind(Inbox::class, DatabaseInbox::class);
        $this->app->bind(InboxQueries::class, DatabaseInboxQueries::class);
        $this->app->bind(ReportWriter::class, OpenSpoutReportWriter::class);
        $this->app->bind(SentMessages::class, DatabaseSentMessages::class);
        $this->app->bind(AutoReplies::class, DatabaseAutoReplies::class);
        $this->app->bind(OpsLog::class, DatabaseOpsLog::class);
        $this->app->bind(Notices::class, DatabaseNotices::class);
        $this->app->bind(StaffMessages::class, DatabaseStaffMessages::class);
        $this->app->bind(StaffMessageQueries::class, DatabaseStaffMessageQueries::class);
        $this->app->bind(Subscribers::class, DatabaseSubscribers::class);
        $this->app->bind(BroadcastQueue::class, QueuedBroadcasts::class);
        $this->app->bind(NewsletterQueue::class, QueuedNewsletter::class);
        $this->app->bind(DailyReportRenderer::class, MpdfDailyReportRenderer::class);
        $this->app->bind(DailyReports::class, DatabaseDailyReports::class);
        $this->app->bind(ReportFiles::class, LocalReportFiles::class);
        $this->app->bind(DailyReportEmailQueue::class, QueuedDailyReportEmails::class);
        $this->app->bind(DailyReportMailer::class, DailyReportEmailMailer::class);
        $this->app->bind(EventDeliveries::class, QueuedEventDeliveries::class);

        // The providers are adapters chosen by configuration (§2.2): a new one is a new class and a
        // line here. "log" writes what would be sent to the log, never on a live system.
        // Production with email not set up never stops the site (owner, 2026-11-22; ops.md §7): the sender that
        // would be real is one that sends nothing and refuses, naming the settings - not sent, Super Admins warned.
        $this->app->bind(EmailSender::class, fn (Application $app): EmailSender => $this->emailSender($app));
        // Codes go through the provider of the phone's country on the Code Countries list (access.md
        // amendments 90, 98): `whatsapp` (Meta's Cloud API, its keys in .env, a missing one never stops the
        // site) or `log`. A new provider is a new adapter and a line here.
        $this->app->bind(SmsSenders::class, fn (Application $app): SmsSenders => new BuiltSmsSenders([
            'whatsapp' => fn (): CodeSender => $app->make(WhatsAppCodeSender::class),
            'log' => fn (): CodeSender => $app->make(LogSmsSender::class),
        ], $app->environment('production')));
        $this->app->bind(SmsCountries::class, DatabaseSmsCountries::class);

        // Access's contracts, which Ops fulfils so that Access never depends on Ops (access.md §2.3,
        // amendment 90): the security messages, and whether a phone's country is on the SMS list.
        $this->app->bind(PhoneCountries::class, ListedPhoneCountries::class);
        $this->app->bind(SecurityMessages::class, OpsSecurityMessages::class);
        // Content's port, the same way (ops.md §9.7 #1): the draft notices a campaign's page offers.
        $this->app->bind(CampaignNotices::class, OpsCampaignNotices::class);

        $this->app->bind(NewsletterList::class, fn (Application $app): NewsletterList => $this->newsletterList($app));
        // The nightly read of Brevo's unsubscribes (§9.6 #1), with the same key: called only while BREVO_NIGHTLY_READ is on.
        $this->app->bind(BrevoUnsubscribeRead::class, fn (Application $app): BrevoUnsubscribeRead => new BrevoUnsubscribeRead(
            $app->make(Factory::class),
            $app->make(ConnectionInterface::class),
            $app->make(CustomerSubscriptions::class),
            $app->make(LoggerInterface::class),
            (string) $app->make('config')->get('services.brevo.key'),
        ));
    }

    public function boot(): void
    {
        if (self::servesPeople($this->app)) {
            self::requireRealDelivery($this->app);
        }

        $this->loadMigrationsFrom(__DIR__.'/Persistence/Migrations');
        $this->loadTranslationsFrom(dirname(__DIR__).'/Presentation/lang', 'ops');
        $this->loadViewsFrom(dirname(__DIR__).'/Presentation/views', 'ops');

        $this->app->make(PermissionCatalog::class)->declare('ops', ...OpsPermissions::definitions());

        // Today's Report in the admin home's greeting row (dashboard.md §2.1), for the daily report's makers.
        $this->app->make(HomeCards::class)->register(
            new HomeCardDto('ops', 'report', OpsPermissions::REPORT_DAILY, TodaysReportCard::class, 10, band: HomeBand::Greeting),
        );

        // Each entry lands with its page (ops.md §4.15, the menu).
        $this->app->make(AdminMenu::class)->register(
            // The Reports section (§4.15 O8, O9).
            new MenuEntryDto('ops', 'reports', 'reports', 'ops.admin.reports', OpsPermissions::REPORT_VIEW, 10, icon: 'reports'),
            new MenuEntryDto('ops', 'daily_reports', 'reports', 'ops.admin.daily-reports', OpsPermissions::REPORT_DAILY, 20, icon: 'daily_reports'),
            new MenuEntryDto('ops', 'notices', 'marketing', 'ops.admin.notices', OpsPermissions::NOTICE_MANAGE, 40, icon: 'notices'),
            new MenuEntryDto('ops', 'messages', 'marketing', 'ops.admin.messages', [OpsPermissions::MESSAGE_SEND_ONE, OpsPermissions::MESSAGE_SEND_MANY], 50, icon: 'messages'),
            new MenuEntryDto('ops', 'subscribers', 'marketing', 'ops.admin.subscribers', OpsPermissions::NEWSLETTER_MANAGE, 60, icon: 'subscribers'),
            new MenuEntryDto('ops', 'sms_countries', 'store_settings', 'ops.admin.sms-countries', OpsPermissions::SMS_COUNTRIES_MANAGE, 26, icon: 'sms'),
        );

        // The two bells, with each page's frame (§4.15 O1, O2), and the shop's full page in the account's menu.
        $this->app->make(AdminFrameParts::class)->register('ops', PanelBellPart::class);
        $this->app->make(ShopFrameParts::class)->register('ops', ShopPart::class);
        $this->app->make(CustomerAccountPages::class)->register(new CustomerAccountPageDto('ops', 'notifications', 'storefront.ops.bell', position: 6));

        // The unsubscribe links' POST is also a mail client's one-click unsubscribe (RFC 8058), which
        // carries no CSRF token: the URL's signature is its authority (`signed:relative` on the route).
        PreventRequestForgery::except(['*/*/unsubscribe/*', '*/*/newsletter/leave/*']);

        // Brevo throws away a webhook answered 503 (docs/retry-mechanism), and an unsubscribe with it, so it is
        // still taken in maintenance; if the work cannot be done, its answer asks Brevo to try again.
        PreventRequestsDuringMaintenance::except(['newsletter/brevo']);
        // Meta retries a notice for 7 days when it is not answered 200, and a failed status with it (ops.md §9.8 point 4).
        PreventRequestsDuringMaintenance::except(['whatsapp/webhook']);

        // The red notice while WhatsApp is not set up (§9.8 W3).
        $this->app->make(AdminBanners::class)->register(WhatsAppBanner::class, EmailBanner::class);

        if ($this->app->runningInConsole()) {
            $this->commands([BrevoCheckCommand::class]);
        }

        // Access's events (§6): each listener on the queue, once per event.
        foreach ([CustomerRegistered::class, GuestBecameCustomer::class, MarketingConsentChanged::class, CustomerLocaleChanged::class, CustomerEmailVerified::class, CustomerBlocked::class, CustomerUnblocked::class, CustomerDeletionScheduled::class, CustomerDeletionCancelled::class] as $event) {
            Event::listen($event, [RefreshNewsletterSubscription::class, 'handle']);
        }

        Event::listen(CustomerAnonymized::class, [ForgetAnonymizedCustomer::class, 'handle']);

        // The other modules' moments (§1.5, §1.6): the customer told, the staff alerted - each listener on
        // the queue, once per event. OrderPlaced has both: the customer's C1 and the staff's S1.
        foreach ([OrderPlaced::class, OrderApproved::class, OrderRejected::class, OrderShipped::class, OrderDelivered::class, OrderCancelled::class, OrderEdited::class, OrderCameBack::class] as $event) {
            Event::listen($event, [TellCustomerAboutOrder::class, 'handle']);
        }

        foreach ([ReturnAccepted::class, ReturnRefused::class, ReturnReceived::class, ReturnCompleted::class] as $event) {
            Event::listen($event, [TellCustomerAboutReturn::class, 'handle']);
        }

        foreach ([RefundRecorded::class, PaymentRecorded::class] as $event) {
            Event::listen($event, [TellCustomerAboutMoney::class, 'handle']);
        }

        Event::listen(PaymentRequested::class, [TellCustomerAboutPaymentRequest::class, 'handle']);

        Event::listen(CouponAssigned::class, [TellCustomersAboutCoupon::class, 'handle']);

        foreach ([PointsEarned::class, PointsChangedByHand::class] as $event) {
            Event::listen($event, [TellCustomerAboutPoints::class, 'handle']);
        }

        foreach ([QuestionAnswered::class, QuestionNotPublished::class, ReviewNotPublished::class] as $event) {
            Event::listen($event, [TellCustomerAboutFeedback::class, 'handle']);
        }

        foreach ([OrderPlaced::class, CompanyApplicationSubmitted::class, LowStockChanged::class, SyncNeedsAttention::class, ReturnRequested::class, GatewayKeyExpiring::class, GatewayKeyRejected::class, PaymentNotAccepted::class, LatePaymentRefundDue::class, TransferOverdue::class, CampaignExpiring::class] as $event) {
            Event::listen($event, [AlertStaff::class, 'handle']);
        }

        // Content's moment for a notice (§1.7): nothing fires it until Content is built.
        Event::listen(CampaignWentLive::class, [StartCampaignNotice::class, 'handle']);

        // Inbox entries, sent messages and handled events keep twelve months (§5). Scheduled work is
        // queued as a job, never run in the scheduler's own process.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->job(PruneOpsLogJob::class)->timezone('Asia/Riyadh')->dailyAt('04:30')->onOneServer();
            // Staff messages and old notices after twelve months, unconfirmed sign-ups after seven days.
            $schedule->job(PruneWrittenMessagesJob::class)->timezone('Asia/Riyadh')->dailyAt('04:40')->onOneServer();
            // A scheduled notice starts within a minute of its time (§1.7).
            $schedule->job(StartDueNoticesJob::class)->everyMinute()->onOneServer();
            // The daily reports' files and rows, twelve months too (§5). Each one's email is queued by the
            // person who switched it on, not here: it must run on their behalf (§1.11).
            $schedule->job(PruneDailyReportsJob::class)->timezone('Asia/Riyadh')->dailyAt('04:50')->onOneServer();
            // C14: the customers whose points expire a week from today, each store's own day (§1.5).
            $schedule->job(RemindExpiringPointsJob::class)->timezone('Asia/Riyadh')->dailyAt('10:00')->onOneServer();
            // The unsubscribes Brevo's webhook lost (§9.6 #1), each list read in its own job; nothing while BREVO_NIGHTLY_READ is off.
            $schedule->job(ReadBrevoUnsubscribesJob::class)->timezone('Asia/Riyadh')->dailyAt('03:20')->onOneServer();
        });

        if (! $this->app->routesAreCached()) {
            $this->loadRoutesFrom(dirname(__DIR__).'/Presentation/shop-routes.php');
            // The panel's own file: the admin session cookie must be set before "web" opens a session.
            $this->loadRoutesFrom(dirname(__DIR__).'/Presentation/admin-routes.php');
            // Brevo's and Meta's webhooks: machine to machine, no session.
            $this->loadRoutesFrom(dirname(__DIR__).'/Presentation/webhook-routes.php');
        }
    }

    /** The Brevo folder the newsletter's lists are made in: a whole number above 0, else none. */
    private static function folderId(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /**
     * Whether this process serves people, so the guard below applies: a web request or a queue worker. Any other
     * console command is left alone: it may be what an operator mends the problem with (`migrate`, `config:clear`,
     * `ops:brevo:check`, `composer install`'s `package:discover`).
     *
     * @param  list<string>|null  $argv  the command line, when not this process's own
     */
    public static function servesPeople(Application $app, ?array $argv = null): bool
    {
        if (! $app->runningInConsole()) {
            return true;
        }

        /** @var list<string> $line */
        $line = $argv ?? ($_SERVER['argv'] ?? []);

        return array_intersect(['queue:work', 'queue:listen'], $line) !== [];
    }

    /**
     * **A production server whose email is not set up still starts and runs** (owner, 2026-11-22; ops.md §7, it
     * replaced "refuses to start"): the problems are a loud log line at most once an hour, naming only each setting, never a
     * value - and the email is not sent (NotSetUpEmailSender), the lists are not kept (NoNewsletterList), and the Super
     * Admins see a red notice (EmailBanner). Called by a web request or a queue worker; any other console command is left
     * alone. `OPS_NEWSLETTER_DRIVER` not `brevo`, a missing list folder and a missing webhook secret are logged the same way.
     *
     * An application with no key is not configured at all (a fresh checkout, where Laravel reports "production" only
     * because there is no .env yet): nothing is said.
     */
    public static function requireRealDelivery(Application $app): void
    {
        $config = $app->make('config');
        $key = $config->get('app.key');

        if (! is_string($key) || $key === '' || ! $app->environment('production')) {
            return;
        }

        $problems = self::deliveryProblems($config);

        if ($problems !== []) {
            self::warnLoudly($app, $problems);
        }
    }

    /**
     * Everything wrong with the delivery settings, by the setting's name, never with a value of a secret.
     *
     * @return array<string, string>
     */
    private static function deliveryProblems(Repository $config): array
    {
        $problems = EmailSetup::problems($config);
        $lists = $config->get('ops.newsletter.driver');
        $secret = $config->get('services.brevo.webhook_secret');

        if ($lists !== 'brevo') {
            $problems['OPS_NEWSLETTER_DRIVER'] = 'OPS_NEWSLETTER_DRIVER is "'.(is_string($lists) ? $lists : '').'": the newsletter\'s lists are not kept.';
        }

        if (self::folderId($config->get('services.brevo.newsletter_folder')) === null) {
            $problems['BREVO_NEWSLETTER_FOLDER_ID'] = 'BREVO_NEWSLETTER_FOLDER_ID is not set: no list could be made.';
        }

        if (! is_string($secret) || trim($secret) === '') {
            $problems['BREVO_WEBHOOK_SECRET'] = 'BREVO_WEBHOOK_SECRET is not set: every unsubscribe Brevo reports would be refused, and thrown away.';
        }

        return $problems;
    }

    /**
     * The email sender: on a development machine and in tests `log` or Brevo as configured; in production Brevo when
     * email is set up, and otherwise the sender that sends nothing (never the log, which would write every link and
     * address to a file).
     */
    private function emailSender(Application $app): EmailSender
    {
        $config = $app->make('config');

        if ($app->environment('production')) {
            $problems = EmailSetup::problems($config);

            return $problems === []
                ? new BrevoEmailSender($app->make(Factory::class), (string) $config->get('services.brevo.key'))
                : new NotSetUpEmailSender(array_keys($problems));
        }

        return match ($config->get('ops.email.driver')) {
            'brevo' => new BrevoEmailSender($app->make(Factory::class), (string) $config->get('services.brevo.key')),
            'log' => $app->make(LogEmailSender::class),
            default => throw new InvalidArgumentException('OPS_EMAIL_DRIVER names no email driver Ops has: "brevo" or "log".'),
        };
    }

    /** The newsletter's lists: as the sender, `log` only outside production; in production Brevo, or none and nothing written. */
    private function newsletterList(Application $app): NewsletterList
    {
        $config = $app->make('config');
        $production = $app->environment('production');
        $brevo = fn (): NewsletterList => new BrevoNewsletterList(
            $app->make(Factory::class),
            $app->make(ConnectionInterface::class),
            $app->make(PlatformApi::class),
            (string) $config->get('services.brevo.key'),
            self::folderId($config->get('services.brevo.newsletter_folder')),
        );

        if ($production) {
            return $config->get('ops.newsletter.driver') === 'brevo' && ! array_key_exists('BREVO_API_KEY', EmailSetup::problems($config)) ? $brevo() : new NoNewsletterList;
        }

        return match ($config->get('ops.newsletter.driver')) {
            'brevo' => $brevo(),
            'log' => $app->make(LogNewsletterList::class),
            default => throw new InvalidArgumentException('OPS_NEWSLETTER_DRIVER names no newsletter driver Ops has: "brevo" or "log".'),
        };
    }

    /**
     * Boot runs on every request: at most once an hour. If the cache is what is away, the line goes anyway.
     *
     * @param  array<string, string>  $problems
     */
    private static function warnLoudly(Application $app, array $problems): void
    {
        try {
            $first = $app->make('cache')->add('ops:delivery-problems-told', true, 3600);
        } catch (Throwable) {
            $first = true;
        }

        if ($first) {
            $app->make(LoggerInterface::class)->critical('Ops: this production server is missing delivery settings. '.implode(' ', $problems));
        }
    }
}
