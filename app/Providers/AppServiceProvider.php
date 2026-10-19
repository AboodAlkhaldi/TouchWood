<?php

declare(strict_types=1);

namespace App\Providers;

use App\Listeners\LogSsrFailure;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Inertia\Ssr\SsrRenderFailed;
use Shared\Infrastructure\Outbox\OutboxRelay;

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

        // The outbox's relay (platform.md §5.6, sales.md §5): every minute, from one server, queued as
        // every scheduled work is (owner, 2026-09-18). It belongs to no module, so it is scheduled here.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $schedule->job(OutboxRelay::class)->everyMinute()->onOneServer();
        });
    }
}
