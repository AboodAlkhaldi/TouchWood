<?php

declare(strict_types=1);

namespace Modules\Platform\Infrastructure;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Filesystem\Factory as Filesystems;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Platform\Application\AuditLog;
use Modules\Platform\Application\Media\ImageVariantGenerator;
use Modules\Platform\Application\Media\InMemoryMediaUsages;
use Modules\Platform\Application\Media\MediaInspector;
use Modules\Platform\Application\Media\MediaSettings;
use Modules\Platform\Application\Media\MediaStorage;
use Modules\Platform\Application\Media\MediaVariantsQueue;
use Modules\Platform\Application\Menu\InMemoryAdminMenu;
use Modules\Platform\Application\PlatformApiImpl;
use Modules\Platform\Application\Query\ListAudit\AuditReader;
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
use Modules\Platform\Infrastructure\Eloquent\DatabaseAuditReader;
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
use Modules\Platform\Infrastructure\Queue\RequeueStuckMediaVariantsJob;
use Modules\Platform\Presentation\Console\CreateCurrencyCommand;
use Modules\Platform\Presentation\Console\CreateStoreCommand;
use Modules\Platform\Presentation\Console\RequeueStuckMediaVariantsCommand;
use Modules\Platform\Presentation\Http\Middleware\ResolveStore;
use Modules\Platform\Presentation\Http\Middleware\ShareStorefront;
use Modules\Platform\Presentation\Http\Middleware\TrackHttpRequest;
use Modules\Platform\Presentation\Http\StorefrontLanguage;
use Modules\Platform\Public\Contracts\AdminMenu;
use Modules\Platform\Public\Contracts\MediaUsages;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Contracts\ReservedPaths;
use Modules\Platform\Public\Contracts\SettingsRegistry;
use Modules\Platform\Public\Dto\MenuEntryDto;
use Modules\Platform\Public\PlatformPermissions;
use Psr\Log\LoggerInterface;
use Shared\Application\ActorContext;
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
        AuditReader::class => DatabaseAuditReader::class,
        StoreRepository::class => EloquentStoreRepository::class,
        CurrencyRepository::class => EloquentCurrencyRepository::class,
        LaravelStoreContext::class => LaravelStoreContext::class,
        MediaRepository::class => DatabaseMediaRepository::class,
        MediaInspector::class => FinfoMediaInspector::class,
        ImageVariantGenerator::class => InterventionImageVariantGenerator::class,
        MediaVariantsQueue::class => LaravelMediaVariantsQueue::class,
        // Process-wide on purpose: the queue worker runs jobs one after another in one process.
        JobActorState::class => JobActorState::class,
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

        // Modules that store media ids register here, so deleting media detaches or refuses.
        $this->app->singleton(InMemoryMediaUsages::class);
        $this->app->alias(InMemoryMediaUsages::class, MediaUsages::class);

        // Modules register their admin menu entries here, Platform included: the menu is built from
        // what each person may do, and grows module by module (stage 2b, P6).
        $this->app->singleton(InMemoryAdminMenu::class);
        $this->app->alias(InMemoryAdminMenu::class, AdminMenu::class);

        // One pattern for {store} on every route, built from every module's reserved paths, so no
        // module imports Platform's interior to register storefront routes. Built after every
        // provider's register() and before any boot(): Laravel copies a global pattern into a route
        // only when the route is created, so a module whose provider boots before Platform's still
        // gets it. Frozen afterwards: a later reservation would be missing from the pattern.
        $this->app->booting(function (Application $app): void {
            $reservedPaths = $app->make(InMemoryReservedPaths::class);
            Route::pattern('store', $reservedPaths->routePattern());
            Route::pattern('locale', $app->make(StorefrontLanguage::class)->routePattern());
            $reservedPaths->freeze();
        });

        // Process-wide: a web server process serves requests only; in the console, TrackHttpRequest
        // marks the requests tests send.
        $this->app->singleton(HttpRequestState::class, fn (Application $app): HttpRequestState => new HttpRequestState(! $app->runningInConsole()));

        // Anything that depends on who is acting lives for one request or one job, never the
        // whole process — a queue worker must not audit or authorize as an earlier job's actor.
        // Access binds the ActorContext (spec §2.5). This wraps it: a queued job acts as the system
        // on behalf of whoever queued it (owner's decision, 2026-09-18).
        $this->app->extend(ActorContext::class, fn (ActorContext $actors, Application $app): ActorContext => $actors instanceof JobAwareActorContext
            ? $actors
            : new JobAwareActorContext($actors, $app->make(JobActorState::class)));
        // The Authorizer is Access's (Access spec §2.5).
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
        $this->app->singleton(StorefrontLanguage::class, fn (): StorefrontLanguage => new StorefrontLanguage(
            array_values(array_map(strval(...), (array) config('platform.locales'))),
            (string) config('app.locale'),
        ));
        $this->app->singleton(MediaReader::class, fn (Application $app): MediaReader => new DatabaseMediaReader(
            $app->make(MediaRepository::class),
            $app->make(MediaStorage::class),
            (int) config('platform.media.private_link_minutes'),
            $app->make(ConnectionInterface::class),
        ));
    }

    public function boot(Router $router): void
    {
        $presentation = dirname(__DIR__).'/Presentation';

        $this->loadMigrationsFrom(__DIR__.'/Persistence/Migrations');
        $this->loadTranslationsFrom($presentation.'/lang', 'platform');

        $router->aliasMiddleware(ResolveStore::ALIAS, ResolveStore::class);
        $router->aliasMiddleware(ShareStorefront::ALIAS, ShareStorefront::class);

        // On every request, so the audit log can tell a web change from a console or queued one.
        $this->app->make(HttpKernel::class)->pushMiddleware(TrackHttpRequest::class);

        $this->app->make(SettingsRegistry::class)->define('platform', ...MediaSettings::definitions());

        /*
        | What Platform puts in the admin menu (stage 2b, P6). Registered at boot like the settings;
        | who is offered each entry is decided per request, by asking the authorizer about the
        | permission named here.
        |
        | Offering is never allowing: the screen behind each of these checks the same permission
        | again in its own read model or handler (handoff 19).
        */
        $this->app->make(AdminMenu::class)->register(
            new MenuEntryDto('platform', 'stores', 'store_settings', 'platform.admin.stores', PlatformPermissions::STORE_VIEW, 10, icon: 'stores'),
            new MenuEntryDto('platform', 'currencies', 'store_settings', 'platform.admin.currencies', PlatformPermissions::CURRENCY_UPDATE, 20, icon: 'billing'),
            new MenuEntryDto('platform', 'settings', 'store_settings', 'platform.admin.settings', PlatformPermissions::SETTINGS_VIEW, 30, icon: 'dashboard'),
            new MenuEntryDto('platform', 'media', 'media', 'platform.admin.media', PlatformPermissions::MEDIA_UPLOAD, 10, icon: 'media'),
            new MenuEntryDto('platform', 'audit', 'audit', 'platform.admin.audit', PlatformPermissions::AUDIT_VIEW, 10, icon: 'audit'),
        );

        // Images whose variant job was lost are queued again (owner's decision, 2026-09-16). Scheduled
        // work runs as a queued job, so its audit source is JOB (owner's decision, 2026-09-18).
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->job(RequeueStuckMediaVariantsJob::class)->everyTenMinutes()->onOneServer();
        });

        $this->carryActorIntoQueuedJobs();

        // A fresh or rolled-back schema must not be served from a cache built on the old one.
        Event::listen(MigrationsEnded::class, function (): void {
            $this->app->make(StoreDirectory::class)->invalidate();
            $this->app->make(SettingValues::class)->invalidate();
        });

        if (! $this->app->routesAreCached()) {
            // Not wrapped in "web" from here: the shop's routes bring their own list, because
            // its session cookie has to be set before "web" opens a session - the same reason the
            // panel's file below is loaded on its own (frontend.md 2.3).
            Route::group([], $presentation.'/routes.php');

            // The panel's own file, and deliberately not inside that group: the admin session
            // cookie has to be set *before* "web" opens a session, and a second "web" around it
            // would open one first (frontend.md 4.1, and Access's own routes for the same reason).
            Route::group([], $presentation.'/admin-routes.php');
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
            $jobs->enter(spl_object_id($event->job), QueuedActor::actorFor($event->job->payload()));
        });

        // JobAttempted fires in a finally block, after a failed job's failed() method has run, on
        // the worker and on the sync queue alike — so failed() still acts as the system.
        Event::listen(JobAttempted::class, function (JobAttempted $event) use ($jobs): void {
            $jobs->leave(spl_object_id($event->job));
        });
    }
}
