<?php

declare(strict_types=1);

namespace Modules\Sales\Infrastructure;

use Illuminate\Support\ServiceProvider;
use Modules\Sales\Infrastructure\External\NothingSoldYet;
use Modules\Sales\Public\Contracts\SalesApi;

/**
 * Sales's wiring (sales.md). Before its build only its public contract exists, answered by a
 * placeholder so the modules built against it (Feedback) run.
 */
final class SalesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SalesApi::class, NothingSoldYet::class);
    }
}
