<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Str;
use Shared\Application\CorrelationId;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request a correlation id (handoff §5.3). It is stored in Laravel's Context, so
 * it appears on every log line, in error responses and audit entries, and travels into every job
 * the request dispatches.
 *
 * Always generated here, never taken from the caller (owner's decision, 2026-09-18): an id sent in
 * the request would let anyone choose what is written into the audit log.
 */
final class AssignCorrelationId
{
    public const string HEADER = 'X-Correlation-Id';

    public function handle(Request $request, Closure $next): Response
    {
        $id = strtolower((string) Str::ulid());

        Context::add(CorrelationId::CONTEXT_KEY, $id);

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set(self::HEADER, $id);

        return $response;
    }
}
