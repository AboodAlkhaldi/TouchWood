<?php

declare(strict_types=1);

namespace Modules\Access\Presentation\Http\Middleware;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Modules\Access\Infrastructure\Http\RequestActor;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every web request starts as a guest; the admin routes then name the staff member signed in.
 * Global, so no route can run as the system.
 */
final readonly class IdentifyRequestActor
{
    public function __construct(
        private RequestActor $actor,
        private Application $app,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->actor->set($this->actor->guest());

        try {
            return $next($request);
        } finally {
            // Tests send requests from the console: the code after one acts as the system again.
            // A web server process keeps it, for work done after the response.
            if ($this->app->runningInConsole()) {
                $this->actor->clear();
            }
        }
    }
}
