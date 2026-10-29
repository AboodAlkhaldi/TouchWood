<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The headers every response carries (handoff §15.4; audit run-2 job F, owner 2026-10-29): no other
 * site may frame a page, a browser never guesses a file's type from its content, and another site
 * linked to learns nothing of the address it was linked from.
 *
 * Only `frame-ancestors` of a Content-Security-Policy: the theme script in the page's head runs
 * without a nonce (frontend.md, edit on System), so a policy on scripts waits until it has one.
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('Content-Security-Policy', "frame-ancestors 'self'");
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'same-origin');

        return $response;
    }
}
