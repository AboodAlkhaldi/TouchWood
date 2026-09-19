<?php

declare(strict_types=1);

namespace Modules\Access\Infrastructure;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\NoPendingMigrations;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Access\Application\Authorization\GrantsReader;
use Modules\Access\Application\Authorization\RoleAuthorizer;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Permission\InMemoryPermissionCatalog;
use Modules\Access\Application\Query\RoleReader;
use Modules\Access\Domain\Repository\RoleAssignmentRepository;
use Modules\Access\Domain\Repository\RoleRepository;
use Modules\Access\Domain\Repository\StaffUserRepository;
use Modules\Access\Infrastructure\Eloquent\CachedGrantsReader;
use Modules\Access\Infrastructure\Eloquent\DatabaseRoleAssignmentRepository;
use Modules\Access\Infrastructure\Eloquent\DatabaseRoleReader;
use Modules\Access\Infrastructure\Eloquent\DatabaseRoleRepository;
use Modules\Access\Infrastructure\Eloquent\DatabaseStaffUserRepository;
use Modules\Access\Infrastructure\Permission\PermissionSync;
use Modules\Access\Public\Contracts\PermissionCatalog;
use Modules\Access\Public\Dto\PermissionDefinitionDto;
use Modules\Access\Public\Enums\PermissionKind;
use Modules\Platform\Public\PlatformPermissions;
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
        $this->app->bind(RoleRepository::class, DatabaseRoleRepository::class);
        $this->app->bind(RoleAssignmentRepository::class, DatabaseRoleAssignmentRepository::class);
        $this->app->bind(RoleReader::class, DatabaseRoleReader::class);
        $this->app->bind(GrantsReader::class, CachedGrantsReader::class);

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
