<?php

declare(strict_types=1);

namespace Modules\Content\Infrastructure;

use Illuminate\Support\ServiceProvider;
use Modules\Access\Public\Contracts\PermissionCatalog;
use Modules\Content\Application\ContentPermissions;
use Modules\Content\Infrastructure\External\NoCampaignsYet;
use Modules\Content\Public\Contracts\ContentApi;

/**
 * Content's wiring (content.md; handoff §13.2): what the shop says and how it is dressed. Registered after
 * Platform, Catalog and Pricing, which it depends on, and after Access, whose public surface it uses to
 * declare its permissions and for nothing else (§9.4 A1; ContentAccessUseTest).
 *
 * PR 2, the foundation: its permissions, its errors and their words, the `content` schema with its slug
 * history, the text format and Translate. `ContentApi` stays answered by the placeholder - no campaign
 * exists yet - until campaigns land (PR 6), so Ops, built against it, runs.
 */
final class ContentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ContentApi::class, NoCampaignsYet::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Persistence/Migrations');
        $this->loadTranslationsFrom(dirname(__DIR__).'/Presentation/lang', 'content');

        // Content sits above Access, so it declares its own permissions through Access's public catalog
        // (Access spec §2.2); Access checks them at boot like its own.
        $this->app->make(PermissionCatalog::class)->declare('content', ...ContentPermissions::definitions());

        if (! $this->app->routesAreCached()) {
            $this->loadRoutesFrom(dirname(__DIR__).'/Presentation/admin-routes.php');
        }
    }
}
