<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Platform\Presentation\Http\InMemoryUnmatchedAddresses;
use Symfony\Component\HttpFoundation\Response;

/**
 * Global middleware (registered by PlatformServiceProvider): a read of an address **no route matched** -
 * `/flash-deals`, `/product/x`, where `{locale}` is only a language - is offered to the modules' answers
 * before it is answered not found (content.md §2.3 row L2), for Content's redirects from the old site.
 *
 * It acts on the answer the site has already made, and only when that answer is a 404 that no route gave,
 * to a GET or a HEAD: anything else - a page, a 404 a page gave, a POST's 404, a 405 - passes through
 * untouched, and so does every 404 nobody redirects, so "not found" stays exactly what it is without this.
 * An address under a reserved first segment is never asked about (InMemoryUnmatchedAddresses).
 *
 * A middleware rather than a fallback route at the root: a route there would itself be a match, so a GET
 * to an address only a POST answers would turn from "method not allowed" into "not found", and it would
 * have to come after every other fallback. Here nothing is matched that was not matched before. Nothing is
 * read from or written to a session: none is open when no route matched.
 */
final readonly class AnswerUnmatchedAddresses
{
    public function __construct(
        private InMemoryUnmatchedAddresses $addresses,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        if ($response->getStatusCode() !== 404 || $request->route() !== null || ! in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return $response;
        }

        $path = InMemoryUnmatchedAddresses::pathOf($request);

        return ($path === null ? null : $this->addresses->answer($path)) ?? $response;
    }
}
