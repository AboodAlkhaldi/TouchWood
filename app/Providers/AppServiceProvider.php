<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\PanelStore;
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
        // One store per request: the middleware that works it out and the screen that asks for it
        // must be holding the same one. Scoped rather than a singleton so nothing survives into
        // the next request - a queue worker or Octane would otherwise serve one person's store to
        // the next person (app/Http/PanelStore.php).
        $this->app->scoped(PanelStore::class);
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
