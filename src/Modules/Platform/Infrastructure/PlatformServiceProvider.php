<?php

declare(strict_types=1);

namespace Modules\Platform\Infrastructure;

use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Platform\Application\AuditLog;
use Modules\Platform\Application\PlatformApiImpl;
use Modules\Platform\Application\Query\StoreDirectory;
use Modules\Platform\Application\Settings\InMemorySettingsRegistry;
use Modules\Platform\Application\Settings\SettingValues;
use Modules\Platform\Domain\Repository\CurrencyRepository;
use Modules\Platform\Domain\Repository\StoreRepository;
use Modules\Platform\Domain\ValueObject\StoreCode;
use Modules\Platform\Infrastructure\Eloquent\CachedStoreDirectory;
use Modules\Platform\Infrastructure\Eloquent\DatabaseAuditLog;
use Modules\Platform\Infrastructure\Eloquent\DatabaseSettings;
use Modules\Platform\Infrastructure\Eloquent\EloquentCurrencyRepository;
use Modules\Platform\Infrastructure\Eloquent\EloquentStoreRepository;
use Modules\Platform\Presentation\Console\CreateCurrencyCommand;
use Modules\Platform\Presentation\Console\CreateStoreCommand;
use Modules\Platform\Presentation\Http\Middleware\ResolveStore;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Contracts\SettingsRegistry;
use Shared\Application\ActorContext;
use Shared\Application\Authorizer;
use Shared\Application\StoreContext;

final class PlatformServiceProvider extends ServiceProvider
{
    /**
     * Stateless, or holding only process-wide state.
     *
     * @var array<class-string, class-string>
     */
    public array $singletons = [
        // Holds the definitions every module declares at boot.
        InMemorySettingsRegistry::class => InMemorySettingsRegistry::class,
        SettingValues::class => DatabaseSettings::class,
        StoreDirectory::class => CachedStoreDirectory::class,
        StoreRepository::class => EloquentStoreRepository::class,
        CurrencyRepository::class => EloquentCurrencyRepository::class,
        LaravelStoreContext::class => LaravelStoreContext::class,
    ];

    public function register(): void
    {
        $this->app->alias(LaravelStoreContext::class, StoreContext::class);
        $this->app->alias(InMemorySettingsRegistry::class, SettingsRegistry::class);

        // Anything that depends on who is acting lives for one request or one job, never the
        // whole process — a queue worker must not audit or authorize as an earlier job's actor.
        $this->app->scoped(ActorContext::class, SystemActorContext::class); // interim until Access
        $this->app->scoped(Authorizer::class, SystemOnlyAuthorizer::class); // interim until Access
        $this->app->scoped(AuditLog::class, DatabaseAuditLog::class);
        $this->app->scoped(PlatformApi::class, PlatformApiImpl::class);
    }

    public function boot(Router $router): void
    {
        $presentation = dirname(__DIR__).'/Presentation';

        $this->loadMigrationsFrom(__DIR__.'/Persistence/Migrations');
        $this->loadViewsFrom($presentation.'/views', 'platform');
        $this->loadTranslationsFrom($presentation.'/lang', 'platform');

        // One pattern for {store} on every route, so other modules never import Platform's
        // interior to register storefront routes.
        Route::pattern('store', StoreCode::ROUTE_PATTERN);
        $router->aliasMiddleware(ResolveStore::ALIAS, ResolveStore::class);

        // A fresh or rolled-back schema must not be served from a cache built on the old one.
        Event::listen(MigrationsEnded::class, function (): void {
            $this->app->make(StoreDirectory::class)->invalidate();
            $this->app->make(SettingValues::class)->invalidate();
        });

        if (! $this->app->routesAreCached()) {
            Route::middleware('web')->group($presentation.'/routes.php');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([CreateCurrencyCommand::class, CreateStoreCommand::class]);
        }
    }
}
