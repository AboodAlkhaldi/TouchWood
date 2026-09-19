<?php

declare(strict_types=1);

namespace Modules\Access\Infrastructure;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Validation\UncompromisedVerifier;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\NoPendingMigrations;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Modules\Access\Application\AccessApiImpl;
use Modules\Access\Application\Authorization\GrantsReader;
use Modules\Access\Application\Authorization\RoleAuthorizer;
use Modules\Access\Application\Messages\SmsGateway;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Permission\InMemoryPermissionCatalog;
use Modules\Access\Application\Query\RoleReader;
use Modules\Access\Application\Security\Codes;
use Modules\Access\Application\Security\PasswordPolicy;
use Modules\Access\Application\Settings\StaffSecuritySettings;
use Modules\Access\Application\Staff\StaffLinks;
use Modules\Access\Domain\Repository\NotificationPreferenceRepository;
use Modules\Access\Domain\Repository\RoleAssignmentRepository;
use Modules\Access\Domain\Repository\RoleRepository;
use Modules\Access\Domain\Repository\StaffTokenRepository;
use Modules\Access\Domain\Repository\StaffUserRepository;
use Modules\Access\Infrastructure\Eloquent\CachedGrantsReader;
use Modules\Access\Infrastructure\Eloquent\DatabaseNotificationPreferenceRepository;
use Modules\Access\Infrastructure\Eloquent\DatabaseRoleAssignmentRepository;
use Modules\Access\Infrastructure\Eloquent\DatabaseRoleReader;
use Modules\Access\Infrastructure\Eloquent\DatabaseRoleRepository;
use Modules\Access\Infrastructure\Eloquent\DatabaseStaffTokenRepository;
use Modules\Access\Infrastructure\Eloquent\DatabaseStaffUserRepository;
use Modules\Access\Infrastructure\Media\StaffAvatarUsage;
use Modules\Access\Infrastructure\Messages\LogSmsGateway;
use Modules\Access\Infrastructure\Messages\TemporarySecurityMessages;
use Modules\Access\Infrastructure\Messages\UrlStaffLinks;
use Modules\Access\Infrastructure\Permission\PermissionSync;
use Modules\Access\Infrastructure\Queue\CancelExpiredSuperAdminInvitationsJob;
use Modules\Access\Infrastructure\Security\HmacCodes;
use Modules\Access\Infrastructure\Security\LaravelPasswordPolicy;
use Modules\Access\Infrastructure\Security\LoggedBreachList;
use Modules\Access\Presentation\Console\CancelSuperAdminInvitationCommand;
use Modules\Access\Presentation\Console\CreateSuperAdminCommand;
use Modules\Access\Presentation\Console\ResendSuperAdminInvitationCommand;
use Modules\Access\Presentation\Console\ResetSuperAdminPhoneCommand;
use Modules\Access\Presentation\Console\RevokeSuperAdminCommand;
use Modules\Access\Public\Contracts\AccessApi;
use Modules\Access\Public\Contracts\PermissionCatalog;
use Modules\Access\Public\Contracts\SecurityMessages;
use Modules\Access\Public\Dto\PermissionDefinitionDto;
use Modules\Access\Public\Enums\PermissionKind;
use Modules\Platform\Public\Contracts\MediaUsages;
use Modules\Platform\Public\Contracts\SettingsRegistry;
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
        $this->app->bind(NotificationPreferenceRepository::class, DatabaseNotificationPreferenceRepository::class);
        $this->app->bind(RoleRepository::class, DatabaseRoleRepository::class);
        $this->app->bind(RoleAssignmentRepository::class, DatabaseRoleAssignmentRepository::class);
        $this->app->bind(RoleReader::class, DatabaseRoleReader::class);
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

        // Replaces Platform's interim authorizer (spec §2.5). Scoped: it depends on who is acting.
        // Until staff sign-in (step 3) the interim ActorContext still reports the system for a web
        // request, so the system may act only outside web requests, as before.
        $this->app->scoped(Authorizer::class, fn (Application $app): Authorizer => new RoleAuthorizer(
            $app->make(ActorContext::class),
            $app->make(InMemoryPermissionCatalog::class),
            $app->make(GrantsReader::class),
            $app->runningInConsole(),
        ));
    }

    public function boot(): void
    {
        $presentation = dirname(__DIR__).'/Presentation';

        $this->loadMigrationsFrom(__DIR__.'/Persistence/Migrations');
        $this->loadTranslationsFrom($presentation.'/lang', 'access');
        $this->loadViewsFrom($presentation.'/views', 'access');

        $this->app->make(SettingsRegistry::class)->define('access', ...StaffSecuritySettings::definitions());
        // Staff avatars are Platform media: deleting one leaves its staff member without it.
        $this->app->make(MediaUsages::class)->register('access', StaffAvatarUsage::class);

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
            ),
            array_keys(PlatformPermissions::all()),
            PlatformPermissions::all(),
        ));

        // Renames and removals name permissions every module declares, so they are checked once
        // every provider has booted.
        $this->app->booted(fn () => $catalog->verify());

        $this->carryPermissionChangesOnMigrate();
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
