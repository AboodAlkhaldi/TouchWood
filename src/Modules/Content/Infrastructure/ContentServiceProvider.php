<?php

declare(strict_types=1);

namespace Modules\Content\Infrastructure;

use Illuminate\Support\ServiceProvider;
use Modules\Content\Infrastructure\External\NoCampaignsYet;
use Modules\Content\Public\Contracts\ContentApi;

/**
 * Content's wiring (handoff §13.2). Before its build only its public contract and events exist, answered by
 * a placeholder so Ops, built against them, runs. Content's build fills this provider in.
 */
final class ContentServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ContentApi::class, NoCampaignsYet::class);
    }
}
