<?php

declare(strict_types=1);

namespace Shared\Infrastructure\Http;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request a correlation id (handoff §5.3). It is stored in Laravel's Context, so
 * it appears on every log line and travels into every job the request dispatches.
 */
final class AssignCorrelationId
{
    public const string HEADER = 'X-Correlation-Id';

    public const string CONTEXT_KEY = 'correlation_id';

    public function handle(Request $request, Closure $next): Response
    {
        $incoming = $request->headers->get(self::HEADER);

        $id = is_string($incoming) && preg_match('/^[A-Za-z0-9-]{8,64}\z/', $incoming) === 1
            ? $incoming
            : strtolower((string) Str::ulid());

        Context::add(self::CONTEXT_KEY, $id);

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set(self::HEADER, $id);

        return $response;
    }
}
