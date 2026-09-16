<?php

namespace Modules\Platform\Infrastructure;

use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Platform\Application\AuditLog;
use Modules\Platform\Application\PlatformApiImpl;
use Modules\Platform\Application\Query\StoreDirectory;
use Modules\Platform\Domain\Repository\CurrencyRepository;
use Modules\Platform\Domain\Repository\StoreRepository;
use Modules\Platform\Infrastructure\Eloquent\CachedStoreDirectory;
use Modules\Platform\Infrastructure\Eloquent\DatabaseAuditLog;
use Modules\Platform\Infrastructure\Eloquent\EloquentCurrencyRepository;
use Modules\Platform\Infrastructure\Eloquent\EloquentStoreRepository;
use Modules\Platform\Presentation\Console\CreateCurrencyCommand;
use Modules\Platform\Presentation\Console\CreateStoreCommand;
use Modules\Platform\Presentation\Http\Middleware\ResolveStore;
use Modules\Platform\Public\Contracts\PlatformApi;
use Shared\Application\ActorContext;
use Shared\Application\Authorizer;
use Shared\Application\StoreContext;

final class PlatformServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    public array $singletons = [
        PlatformApi::class => PlatformApiImpl::class,
        AuditLog::class => DatabaseAuditLog::class,
        StoreDirectory::class => CachedStoreDirectory::class,
        StoreRepository::class => EloquentStoreRepository::class,
        CurrencyRepository::class => EloquentCurrencyRepository::class,
        LaravelStoreContext::class => LaravelStoreContext::class,
        // Interim until Access exists; Access replaces both bindings.
        ActorContext::class => SystemActorContext::class,
        Authorizer::class => SystemOnlyAuthorizer::class,
    ];

    public function register(): void
    {
        $this->app->alias(LaravelStoreContext::class, StoreContext::class);
    }

    public function boot(Router $router): void
    {
        $presentation = dirname(__DIR__).'/Presentation';

        $this->loadMigrationsFrom(__DIR__.'/Persistence/Migrations');
        $this->loadViewsFrom($presentation.'/views', 'platform');
        $this->loadTranslationsFrom($presentation.'/lang', 'platform');

        $router->aliasMiddleware(ResolveStore::ALIAS, ResolveStore::class);

        if (! $this->app->routesAreCached()) {
            Route::middleware('web')->group($presentation.'/routes.php');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([CreateCurrencyCommand::class, CreateStoreCommand::class]);
        }
    }
}
