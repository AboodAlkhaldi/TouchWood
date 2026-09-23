<?php

declare(strict_types=1);

namespace Modules\Access\Infrastructure;

use Illuminate\Cache\RateLimiter;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\NoPendingMigrations;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Routing\Router;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use LogicException;
use Modules\Access\Application\AccessApiImpl;
use Modules\Access\Application\Authorization\GrantsReader;
use Modules\Access\Application\Authorization\RoleAuthorizer;
use Modules\Access\Application\Command\ChangeOwnCustomerPassword\ChangeOwnCustomerPasswordHandler;
use Modules\Access\Application\Command\RequestAccountDeletion\RequestAccountDeletionHandler;
use Modules\Access\Application\Command\SignInCustomer\SignInCustomerHandler;
use Modules\Access\Application\Command\SignInStaff\SignInStaffHandler;
use Modules\Access\Application\Customer\CustomerLinks;
use Modules\Access\Application\Customer\GuestVisitors;
use Modules\Access\Application\Messages\SmsGateway;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Permission\InMemoryPermissionCatalog;
use Modules\Access\Application\Query\CustomerReader;
use Modules\Access\Application\Query\RoleReader;
use Modules\Access\Application\Query\StaffReader;
use Modules\Access\Application\Security\Codes;
use Modules\Access\Application\Security\OwnPasswordCheck;
use Modules\Access\Application\Security\PasswordPolicy;
use Modules\Access\Application\Security\SignInLimits;
use Modules\Access\Application\Session\CustomerSessions;
use Modules\Access\Application\Session\StaffSessions;
use Modules\Access\Application\Settings\CustomerSecuritySettings;
use Modules\Access\Application\Settings\StaffSecuritySettings;
use Modules\Access\Application\Staff\StaffLinks;
use Modules\Access\Domain\Repository\AddressRepository;
use Modules\Access\Domain\Repository\CustomerRepository;
use Modules\Access\Domain\Repository\CustomerTokenRepository;
use Modules\Access\Domain\Repository\NotificationPreferenceRepository;
use Modules\Access\Domain\Repository\RoleAssignmentRepository;
use Modules\Access\Domain\Repository\RoleRepository;
use Modules\Access\Domain\Repository\StaffTokenRepository;
use Modules\Access\Domain\Repository\StaffUserRepository;
use Modules\Access\Domain\Repository\StoreAddressFormatRepository;
use Modules\Access\Infrastructure\Eloquent\CachedGrantsReader;
use Modules\Access\Infrastructure\Eloquent\CachedStoreAddressFormatRepository;
use Modules\Access\Infrastructure\Eloquent\DatabaseAddressRepository;
use Modules\Access\Infrastructure\Eloquent\DatabaseCustomerReader;
use Modules\Access\Infrastructure\Eloquent\DatabaseCustomerRepository;
use Modules\Access\Infrastructure\Eloquent\DatabaseCustomerTokenRepository;
use Modules\Access\Infrastructure\Eloquent\DatabaseNotificationPreferenceRepository;
use Modules\Access\Infrastructure\Eloquent\DatabaseRoleAssignmentRepository;
use Modules\Access\Infrastructure\Eloquent\DatabaseRoleReader;
use Modules\Access\Infrastructure\Eloquent\DatabaseRoleRepository;
use Modules\Access\Infrastructure\Eloquent\DatabaseStaffReader;
use Modules\Access\Infrastructure\Eloquent\DatabaseStaffTokenRepository;
use Modules\Access\Infrastructure\Eloquent\DatabaseStaffUserRepository;
use Modules\Access\Infrastructure\Http\CookieGuestVisitors;
use Modules\Access\Infrastructure\Http\CustomerSessionHandler;
use Modules\Access\Infrastructure\Http\LaravelCustomerSessions;
use Modules\Access\Infrastructure\Http\LaravelStaffSessions;
use Modules\Access\Infrastructure\Http\RequestActor;
use Modules\Access\Infrastructure\Http\RequestActorContext;
use Modules\Access\Infrastructure\Listener\WriteStartingAddressFormat;
use Modules\Access\Infrastructure\Media\StaffAvatarUsage;
use Modules\Access\Infrastructure\Messages\LogSmsGateway;
use Modules\Access\Infrastructure\Messages\TemporarySecurityMessages;
use Modules\Access\Infrastructure\Messages\UrlCustomerLinks;
use Modules\Access\Infrastructure\Messages\UrlStaffLinks;
use Modules\Access\Infrastructure\Permission\PermissionSync;
use Modules\Access\Infrastructure\Queue\AnonymizeDueAccountsJob;
use Modules\Access\Infrastructure\Queue\CancelExpiredSuperAdminInvitationsJob;
use Modules\Access\Infrastructure\Security\HmacCodes;
use Modules\Access\Infrastructure\Security\LaravelPasswordPolicy;
use Modules\Access\Infrastructure\Security\LoggedBreachList;
use Modules\Access\Presentation\Console\CancelSuperAdminInvitationCommand;
use Modules\Access\Presentation\Console\CreateSuperAdminCommand;
use Modules\Access\Presentation\Console\ResendSuperAdminInvitationCommand;
use Modules\Access\Presentation\Console\ResetSuperAdminPhoneCommand;
use Modules\Access\Presentation\Console\RevokeSuperAdminCommand;
use Modules\Access\Presentation\Http\Middleware\IdentifyCustomer;
use Modules\Access\Presentation\Http\Middleware\IdentifyRequestActor;
use Modules\Access\Presentation\Http\Middleware\IdentifyStaff;
use Modules\Access\Presentation\Http\Middleware\RequireCustomer;
use Modules\Access\Presentation\Http\Middleware\RequireStaff;
use Modules\Access\Presentation\Http\Middleware\ShareAdminPage;
use Modules\Access\Presentation\Http\Middleware\UseAdminSession;
use Modules\Access\Presentation\Http\Middleware\UseStorefrontSession;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\Access\Public\Contracts\PermissionCatalog;
use Modules\Access\Public\Contracts\SecurityMessages;
use Modules\Access\Public\Dto\PermissionDefinitionDto;
use Modules\Access\Public\Enums\PermissionGroup;
use Modules\Access\Public\Enums\PermissionKind;
use Modules\Platform\Public\Contracts\AdminMenu;
use Modules\Platform\Public\Contracts\MediaUsages;
use Modules\Platform\Public\Contracts\SettingsRegistry;
use Modules\Platform\Public\Dto\MenuEntryDto;
use Modules\Platform\Public\Events\StoreCreated;
use Modules\Platform\Public\PlatformPermissions;
use Psr\Log\LoggerInterface;
use Shared\Application\ActorContext;
use Shared\Application\Authorizer;

final class AccessServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bound here, not in $singletons: other modules declare their permissions in boot(), and
        // this provider declares its own and Platform's below.
        $this->app->singleton(InMemoryPermissionCatalog::class);
        $this->app->alias(InMemoryPermissionCatalog::class, PermissionCatalog::class);

        $this->app->bind(StaffUserRepository::class, DatabaseStaffUserRepository::class);
        $this->app->bind(StaffTokenRepository::class, DatabaseStaffTokenRepository::class);
        $this->app->bind(CustomerRepository::class, DatabaseCustomerRepository::class);
        $this->app->bind(CustomerTokenRepository::class, DatabaseCustomerTokenRepository::class);
        $this->app->bind(CustomerLinks::class, UrlCustomerLinks::class);
        $this->app->bind(GuestVisitors::class, CookieGuestVisitors::class);
        $this->app->scoped(CustomerSessions::class, LaravelCustomerSessions::class);
        $this->app->bind(AddressRepository::class, DatabaseAddressRepository::class);
        $this->app->bind(StoreAddressFormatRepository::class, CachedStoreAddressFormatRepository::class);

        // Staff and customers count wrong passwords the same way, to their own numbers and on their
        // own keys: a busy shop address never locks the admin panel, or the other way round.
        $this->app->when([SignInStaffHandler::class, OwnPasswordCheck::class])
            ->needs(SignInLimits::class)
            ->give(fn (Application $app): SignInLimits => new SignInLimits(
                $app->make(RateLimiter::class),
                $app->make(StaffSecuritySettings::class),
                'staff',
            ));
        $this->app->when([SignInCustomerHandler::class, ChangeOwnCustomerPasswordHandler::class, RequestAccountDeletionHandler::class])
            ->needs(SignInLimits::class)
            ->give(fn (Application $app): SignInLimits => new SignInLimits(
                $app->make(RateLimiter::class),
                $app->make(CustomerSecuritySettings::class),
                'customer',
            ));
        $this->app->bind(NotificationPreferenceRepository::class, DatabaseNotificationPreferenceRepository::class);
        $this->app->bind(RoleRepository::class, DatabaseRoleRepository::class);
        $this->app->bind(RoleAssignmentRepository::class, DatabaseRoleAssignmentRepository::class);
        $this->app->bind(RoleReader::class, DatabaseRoleReader::class);
        $this->app->bind(CustomerReader::class, DatabaseCustomerReader::class);
        $this->app->bind(StaffReader::class, DatabaseStaffReader::class);
        $this->app->bind(GrantsReader::class, CachedGrantsReader::class);
        $this->app->bind(AccessApi::class, AccessApiImpl::class);

        $this->app->bind(PasswordPolicy::class, LaravelPasswordPolicy::class);
        // Laravel's verifier comes from a deferred provider, which would replace a plain binding
        // when it loads; extend() wraps whatever it registers.
        $this->app->extend(UncompromisedVerifier::class, fn (UncompromisedVerifier $verifier, Application $app): UncompromisedVerifier => new LoggedBreachList(
            $app->make(HttpFactory::class),
            $app->make(LoggerInterface::class),
        ));
        $this->app->bind(StaffLinks::class, UrlStaffLinks::class);
        // SMS codes are stored as an HMAC keyed with the application key (Codes).
        $this->app->singleton(Codes::class, fn (Application $app): Codes => new HmacCodes((string) $app->make('config')->get('app.key')));

        // Access sends its own security messages until Ops binds its own: one binding changes
        // (owner's decision, 2026-09-18).
        $this->app->bind(SecurityMessages::class, TemporarySecurityMessages::class);
        $this->app->bind(SmsGateway::class, fn (Application $app): SmsGateway => match ($app->make('config')->get('access.sms.driver')) {
            // It writes every code to the log: never on a live system.
            'log' => $app->environment('production')
                ? throw new InvalidArgumentException('The "log" SMS driver writes codes to the log and never runs in production: set ACCESS_SMS_DRIVER to a real provider.')
                : $app->make(LogSmsGateway::class),
            default => throw new InvalidArgumentException('ACCESS_SMS_DRIVER names no SMS driver Access has; only "log" exists until the provider is chosen.'),
        });

        // Who is acting (spec §2.5): a web request's session names them, else a guest; outside a web
        // request the system. Scoped, never instance(): Platform wraps this binding so a queued job
        // acts as the system for whoever queued it.
        $this->app->scoped(RequestActor::class);
        $this->app->scoped(ActorContext::class, fn (Application $app): ActorContext => new RequestActorContext(
            $app->make(RequestActor::class),
            ! $app->runningInConsole(),
        ));
        $this->app->scoped(StaffSessions::class, LaravelStaffSessions::class);

        // The real permission check (spec §2.5). Scoped: it depends on who is acting.
        $this->app->scoped(Authorizer::class, fn (Application $app): Authorizer => new RoleAuthorizer(
            $app->make(ActorContext::class),
            $app->make(InMemoryPermissionCatalog::class),
            $app->make(GrantsReader::class),
        ));
    }

    public function boot(): void
    {
        self::requireRealMailer($this->app);

        $presentation = dirname(__DIR__).'/Presentation';

        $this->loadMigrationsFrom(__DIR__.'/Persistence/Migrations');
        $this->loadTranslationsFrom($presentation.'/lang', 'access');
        $this->loadViewsFrom($presentation.'/views', 'access');

        // Every web request starts as a guest, so no route ever runs as the system.
        $this->app->make(HttpKernel::class)->pushMiddleware(IdentifyRequestActor::class);

        // The storefront's own session driver: the same database driver, writing the customer
        // each row belongs to, so a deleted account's sessions go with it (owner, 2026-09-21).
        $this->app->make(SessionManager::class)->extend(
            UseStorefrontSession::DRIVER,
            fn (Application $app): CustomerSessionHandler => new CustomerSessionHandler(
                $app->make('db')->connection($app->make('config')->get('session.connection')),
                (string) $app->make('config')->get('session.table', 'sessions'),
                (int) $app->make('config')->get('session.lifetime'),
                $app,
            ),
        );

        $router = $this->app->make(Router::class);
        $router->aliasMiddleware(UseAdminSession::ALIAS, UseAdminSession::class);
        $router->aliasMiddleware(IdentifyStaff::ALIAS, IdentifyStaff::class);
        $router->aliasMiddleware(RequireStaff::ALIAS, RequireStaff::class);
        $router->aliasMiddleware(ShareAdminPage::ALIAS, ShareAdminPage::class);
        $router->aliasMiddleware(UseStorefrontSession::ALIAS, UseStorefrontSession::class);
        $router->aliasMiddleware(IdentifyCustomer::ALIAS, IdentifyCustomer::class);
        $router->aliasMiddleware(RequireCustomer::ALIAS, RequireCustomer::class);

        if (! $this->app->routesAreCached()) {
            $this->loadRoutesFrom($presentation.'/routes.php');
        }

        $this->app->make(SettingsRegistry::class)->define(
            'access',
            ...StaffSecuritySettings::definitions(),
            ...CustomerSecuritySettings::definitions(),
        );
        // Staff avatars are Platform media: deleting one leaves its staff member without it.
        $this->app->make(MediaUsages::class)->register('access', StaffAvatarUsage::class);

        // A store opened later starts with the standard address form, so its customers can save an
        // address before staff ever open the format screen (amendment 41).
        Event::listen(StoreCreated::class, [WriteStartingAddressFormat::class, 'handle']);

        if ($this->app->runningInConsole()) {
            $this->commands([
                CreateSuperAdminCommand::class,
                RevokeSuperAdminCommand::class,
                ResetSuperAdminPhoneCommand::class,
                ResendSuperAdminInvitationCommand::class,
                CancelSuperAdminInvitationCommand::class,
            ]);
        }

        // A Super Admin invitation left unaccepted is cancelled and freed (amendment 30). Scheduled
        // work is queued as a job, never command() or call() (owner's decision, 2026-09-18).
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->job(CancelExpiredSuperAdminInvitationsJob::class)->everyTenMinutes()->onOneServer();
            // The deletions whose fourteen days have passed (amendment 43): 03:00 in Riyadh, which
            // is midnight where the application runs.
            $schedule->job(AnonymizeDueAccountsJob::class)->dailyAt('00:00')->onOneServer();
        });

        $catalog = $this->app->make(InMemoryPermissionCatalog::class);
        $catalog->declare('access', ...AccessPermissions::definitions());

        // Platform sits below Access and cannot reach this catalog, so it publishes its list and
        // Access declares it (Access spec §2.5).
        $catalog->declare('platform', ...array_map(
            fn (string $name, array $permission): PermissionDefinitionDto => new PermissionDefinitionDto(
                $name,
                reserved: $permission['reserved'],
                kind: $permission['storeFree'] ? PermissionKind::Global : PermissionKind::PerStore,
                // Platform names its business area as a string, having no sight of Access's types
                // (stage 2b, P2); an area Access does not know throws here, at boot.
                group: self::businessArea($name, $permission['group']),
            ),
            array_keys(PlatformPermissions::all()),
            PlatformPermissions::all(),
        ));

        // Renames and removals name permissions every module declares, so they are checked once
        // every provider has booted.
        $this->app->booted(fn () => $catalog->verify());

        /*
        | What Access puts in the admin menu (stage 2b, P6). Registered at boot like the settings
        | and the media usages; who is offered each entry is decided per request, by asking the
        | authorizer about the permission named here.
        |
        | Offering is never allowing: the screen behind each of these checks the same permission
        | again in its own handler (handoff §19).
        */
        $this->app->make(AdminMenu::class)->register(
            new MenuEntryDto('access', 'staff', PermissionGroup::StaffAndPermissions->value, 'access.staff.list', AccessPermissions::STAFF_VIEW, 10),
            new MenuEntryDto('access', 'roles', PermissionGroup::StaffAndPermissions->value, 'access.staff.roles', AccessPermissions::ROLE_MANAGE, 20),
        );

        $this->carryPermissionChangesOnMigrate();
    }

    /**
     * The business area Platform named, as the type Access holds (stage 2b, P2). Platform publishes
     * a plain string because it sits below Access and cannot see its enums, so a typo there would
     * otherwise surface at boot as a bare ValueError naming the enum rather than the mistake
     * (review of step 0).
     */
    private static function businessArea(string $permission, ?string $group): ?PermissionGroup
    {
        if ($group === null) {
            return null;
        }

        return PermissionGroup::tryFrom($group) ?? throw new LogicException(
            "Platform puts \"{$permission}\" in the business area \"{$group}\", which Access does not know. The areas are: "
            .implode(', ', array_map(static fn (PermissionGroup $area): string => $area->value, PermissionGroup::cases())).'.'
        );
    }

    /**
     * Invitation and password reset links go by email. A production server with no real mailer —
     * Laravel's default is `log` — would write them to the log file and send nothing, so it refuses
     * to start, as the `log` SMS driver refuses to send (owner's decision, 2026-09-19).
     *
     * @throws InvalidArgumentException
     */
    public static function requireRealMailer(Application $app): void
    {
        $config = $app->make('config');
        $key = $config->get('app.key');

        // An application with no key is not configured at all: a fresh checkout, where Laravel
        // reports "production" only because there is no .env yet (composer install runs
        // package:discover before it exists). Nothing can be sent from it, so there is nothing to
        // protect; a real production server always has a key.
        if (! is_string($key) || $key === '') {
            return;
        }

        $mailer = $config->get('mail.default');
        $name = is_string($mailer) ? $mailer : '';

        if ($app->environment('production') && in_array($name, ['', 'log', 'array'], true)) {
            throw new InvalidArgumentException('MAIL_MAILER is "'.$name.'": invitation and password reset links would be written to the log, not sent. Production never starts without a real mailer.');
        }
    }

    /**
     * At the end of every `php artisan migrate`, also when there is nothing to migrate (Laravel
     * then fires NoPendingMigrations instead of MigrationsEnded), renamed and removed permissions
     * reach the roles (owner's decision, 2026-09-19). Not on a rollback, nor with --pretend when
     * something was pending; NoPendingMigrations does not say whether the run is a pretend one, so
     * `migrate --pretend` with nothing to migrate still carries them.
     */
    private function carryPermissionChangesOnMigrate(): void
    {
        Event::listen(MigrationsEnded::class, function (MigrationsEnded $event): void {
            if ($event->options['pretend'] ?? false) {
                return;
            }

            $sync = $this->app->make(PermissionSync::class);
            $sync->refreshEveryone();

            if ($event->method === 'up') {
                $sync->run();
            }
        });

        Event::listen(NoPendingMigrations::class, function (NoPendingMigrations $event): void {
            if ($event->method === 'up') {
                $this->app->make(PermissionSync::class)->run();
            }
        });
    }
}
