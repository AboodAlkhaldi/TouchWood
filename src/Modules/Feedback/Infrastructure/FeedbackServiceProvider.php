<?php

declare(strict_types=1);

namespace Modules\Feedback\Infrastructure;

use Illuminate\Support\ServiceProvider;
use Modules\Access\Public\Contracts\PermissionCatalog;
use Modules\Feedback\Application\FeedbackPermissions;
use Modules\Feedback\Application\FeedbackSettings;
use Modules\Platform\Public\Contracts\SettingsRegistry;

/**
 * Feedback (feedback.md). Registered after Access, Catalog and Sales: it declares its permissions
 * through Access's public catalog, its setting through Platform, and depends on Platform, Access,
 * Catalog and Sales only (handoff §4.4).
 */
final class FeedbackServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Persistence/Migrations');
        $this->loadTranslationsFrom(dirname(__DIR__).'/Presentation/lang', 'feedback');

        $this->app->make(PermissionCatalog::class)->declare('feedback', ...FeedbackPermissions::definitions());
        // Stars and the rating sort in a store's lists (§1.2), on Platform's settings page.
        $this->app->make(SettingsRegistry::class)->define('feedback', ...FeedbackSettings::definitions());
    }
}
