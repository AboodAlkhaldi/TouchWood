<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Platform\Infrastructure\HttpRequestState;
use Symfony\Component\HttpFoundation\Response;

/**
 * Global middleware (registered by PlatformServiceProvider): marks the time an HTTP request is
 * being handled, so the audit log can tell a web change from a console or queued one.
 */
final readonly class TrackHttpRequest
{
    public function __construct(
        private HttpRequestState $state,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $this->state->enter();

        try {
            /** @var Response */
            return $next($request);
        } finally {
            $this->state->leave();
        }
    }
}
