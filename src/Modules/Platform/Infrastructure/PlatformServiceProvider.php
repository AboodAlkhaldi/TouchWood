<?php

declare(strict_types=1);

namespace Modules\Platform\Infrastructure;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Platform\Application\AuditLog;
use Modules\Platform\Application\Media\ImageVariantGenerator;
use Modules\Platform\Application\Media\MediaInspector;
use Modules\Platform\Application\Media\MediaSettings;
use Modules\Platform\Application\Media\MediaStorage;
use Modules\Platform\Application\Media\MediaVariantsQueue;
use Modules\Platform\Application\PlatformApiImpl;
use Modules\Platform\Application\Query\MediaReader;
use Modules\Platform\Application\Query\StoreDirectory;
use Modules\Platform\Application\Routing\InMemoryReservedPaths;
use Modules\Platform\Application\Settings\InMemorySettingsRegistry;
use Modules\Platform\Application\Settings\SettingValues;
use Modules\Platform\Domain\Repository\CurrencyRepository;
use Modules\Platform\Domain\Repository\MediaRepository;
use Modules\Platform\Domain\Repository\StoreRepository;
use Modules\Platform\Infrastructure\Eloquent\CachedStoreDirectory;
use Modules\Platform\Infrastructure\Eloquent\DatabaseAuditLog;
use Modules\Platform\Infrastructure\Eloquent\DatabaseMediaReader;
use Modules\Platform\Infrastructure\Eloquent\DatabaseMediaRepository;
use Modules\Platform\Infrastructure\Eloquent\DatabaseSettings;
use Modules\Platform\Infrastructure\Eloquent\EloquentCurrencyRepository;
use Modules\Platform\Infrastructure\Eloquent\EloquentStoreRepository;
use Modules\Platform\Infrastructure\External\FinfoMediaInspector;
use Modules\Platform\Infrastructure\External\InterventionImageVariantGenerator;
use Modules\Platform\Infrastructure\External\LaravelMediaStorage;
use Modules\Platform\Infrastructure\Queue\JobActorState;
use Modules\Platform\Infrastructure\Queue\JobAwareActorContext;
use Modules\Platform\Infrastructure\Queue\LaravelMediaVariantsQueue;
use Modules\Platform\Infrastructure\Queue\QueuedActor;
use Modules\Platform\Presentation\Console\CreateCurrencyCommand;
use Modules\Platform\Presentation\Console\CreateStoreCommand;
use Modules\Platform\Presentation\Console\RequeueStuckMediaVariantsCommand;
use Modules\Platform\Presentation\Http\Middleware\ResolveStore;
use Modules\Platform\Presentation\Http\Middleware\TrackHttpRequest;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Contracts\ReservedPaths;
use Modules\Platform\Public\Contracts\SettingsRegistry;
use Psr\Log\LoggerInterface;
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
        MediaRepository::class => DatabaseMediaRepository::class,
        MediaInspector::class => FinfoMediaInspector::class,
        ImageVariantGenerator::class => InterventionImageVariantGenerator::class,
        MediaVariantsQueue::class => LaravelMediaVariantsQueue::class,
        // Process-wide on purpose: the queue worker runs jobs one after another in one process.
        JobActorState::class => JobActorState::class,
        HttpRequestState::class => HttpRequestState::class,
    ];

    public function register(): void
    {
        $this->app->alias(LaravelStoreContext::class, StoreContext::class);
        $this->app->alias(InMemorySettingsRegistry::class, SettingsRegistry::class);
        // Bound here, not in $singletons: Laravel applies that list only after register() returns, and
        // Platform reserves its own paths just below. Other modules reserve in their register().
        $this->app->singleton(InMemoryReservedPaths::class);
        $this->app->alias(InMemoryReservedPaths::class, ReservedPaths::class);

        // Paths the application keeps for itself until a module that owns them exists: the health
        // check, built assets, public files and signed file links, the admin panel and the API.
        $this->app->make(ReservedPaths::class)->reserve('platform', 'up', 'build', 'storage', 'admin', 'api');

        // Anything that depends on who is acting lives for one request or one job, never the
        // whole process — a queue worker must not audit or authorize as an earlier job's actor.
        $this->app->scoped(ActorContext::class, SystemActorContext::class); // interim until Access
        // Wraps this binding and Access's later one: a queued job acts as the system on behalf of
        // whoever queued it (owner's decision, 2026-09-18).
        $this->app->extend(ActorContext::class, fn (ActorContext $actors, Application $app): ActorContext => $actors instanceof JobAwareActorContext
            ? $actors
            : new JobAwareActorContext($actors, $app->make(JobActorState::class)));
        // Interim until Access. Web requests are refused: with no login, nobody there is the system.
        $this->app->scoped(Authorizer::class, fn (Application $app): Authorizer => new SystemOnlyAuthorizer(
            $app->make(ActorContext::class),
            $app->runningInConsole(),
        ));
        $this->app->scoped(AuditLog::class, DatabaseAuditLog::class);
        $this->app->scoped(PlatformApi::class, PlatformApiImpl::class);

        $this->app->singleton(MediaStorage::class, fn (Application $app): MediaStorage => new LaravelMediaStorage(
            $app->make(Filesystems::class),
            $app->make(LoggerInterface::class),
            [
                'public_disk' => (string) config('platform.media.public_disk'),
                'private_disk' => (string) config('platform.media.private_disk'),
            ],
        ));
        $this->app->singleton(MediaReader::class, fn (Application $app): MediaReader => new DatabaseMediaReader(
            $app->make(MediaRepository::class),
            $app->make(MediaStorage::class),
            (int) config('platform.media.private_link_minutes'),
        ));
    }

    public function boot(Router $router): void
    {
        $presentation = dirname(__DIR__).'/Presentation';

        $this->loadMigrationsFrom(__DIR__.'/Persistence/Migrations');
        $this->loadViewsFrom($presentation.'/views', 'platform');
        $this->loadTranslationsFrom($presentation.'/lang', 'platform');

        // One pattern for {store} on every route, built from every module's reserved paths, so no
        // module imports Platform's interior to register storefront routes. Frozen afterwards: a
        // later reservation would be missing from the pattern.
        $reservedPaths = $this->app->make(InMemoryReservedPaths::class);
        Route::pattern('store', $reservedPaths->routePattern());
        $reservedPaths->freeze();
        $router->aliasMiddleware(ResolveStore::ALIAS, ResolveStore::class);

        // On every request, so the audit log can tell a web change from a console or queued one.
        $this->app->make(HttpKernel::class)->pushMiddleware(TrackHttpRequest::class);

        $this->app->make(SettingsRegistry::class)->define('platform', ...MediaSettings::definitions());

        // Images whose variant job was lost are queued again (owner's decision, 2026-09-16).
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->command(RequeueStuckMediaVariantsCommand::NAME)->everyTenMinutes()->withoutOverlapping()->onOneServer();
        });

        $this->carryActorIntoQueuedJobs();

        // A fresh or rolled-back schema must not be served from a cache built on the old one.
        Event::listen(MigrationsEnded::class, function (): void {
            $this->app->make(StoreDirectory::class)->invalidate();
            $this->app->make(SettingValues::class)->invalidate();
        });

        if (! $this->app->routesAreCached()) {
            Route::middleware('web')->group($presentation.'/routes.php');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([CreateCurrencyCommand::class, CreateStoreCommand::class, RequeueStuckMediaVariantsCommand::class]);
        }
    }

    /**
     * The actor who queues a job is written into its payload; while the job runs it acts as the
     * system on that actor's behalf, and afterwards the previous actor is back (with the "sync"
     * queue a job runs inside the request that queued it).
     */
    private function carryActorIntoQueuedJobs(): void
    {
        Queue::createPayloadUsing(fn (): array => QueuedActor::payloadFor($this->app->make(ActorContext::class)->current()));

        $jobs = $this->app->make(JobActorState::class);

        Event::listen(JobProcessing::class, function (JobProcessing $event) use ($jobs): void {
            $jobs->enter(QueuedActor::actorFor($event->job->payload()));
        });

        Event::listen([JobProcessed::class, JobExceptionOccurred::class], function () use ($jobs): void {
            $jobs->leave();
        });
    }
}
