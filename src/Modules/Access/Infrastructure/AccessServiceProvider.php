<?php

declare(strict_types=1);

namespace Modules\Access\Infrastructure;

use Illuminate\Support\ServiceProvider;
use Modules\Access\Application\Permission\AccessPermissions;
use Modules\Access\Application\Permission\InMemoryPermissionCatalog;
use Modules\Access\Public\Contracts\PermissionCatalog;
use Modules\Access\Public\Dto\PermissionDefinitionDto;
use Modules\Platform\Public\PlatformPermissions;

final class AccessServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bound here, not in $singletons: other modules declare their permissions in boot(), and
        // this provider declares its own and Platform's below.
        $this->app->singleton(InMemoryPermissionCatalog::class);
        $this->app->alias(InMemoryPermissionCatalog::class, PermissionCatalog::class);
    }

    public function boot(): void
    {
        $presentation = dirname(__DIR__).'/Presentation';

        $this->loadMigrationsFrom(__DIR__.'/Persistence/Migrations');
        $this->loadTranslationsFrom($presentation.'/lang', 'access');

        $catalog = $this->app->make(PermissionCatalog::class);
        $catalog->declare('access', ...AccessPermissions::definitions());

        // Platform sits below Access and cannot reach this catalog, so it publishes its list and
        // Access declares it (Access spec §2.5).
        $catalog->declare('platform', ...array_map(
            fn (string $name, bool $reserved): PermissionDefinitionDto => new PermissionDefinitionDto($name, reserved: $reserved),
            array_keys(PlatformPermissions::all()),
            PlatformPermissions::all(),
        ));
    }
}
