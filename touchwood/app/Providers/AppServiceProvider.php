<?php

declare(strict_types=1);

namespace App\Providers;

use App\Listeners\LogSsrFailure;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Inertia\Ssr\SsrRenderFailed;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // A page the server could not render is served anyway, by the browser, and written down
        // (frontend.md §1.3). Nobody sees an error because of SSR.
        Event::listen(SsrRenderFailed::class, LogSsrFailure::class);
    }
}
