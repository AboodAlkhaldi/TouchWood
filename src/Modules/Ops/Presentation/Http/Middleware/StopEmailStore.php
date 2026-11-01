<?php

declare(strict_types=1);

namespace Modules\Ops\Presentation\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Modules\Platform\Public\Contracts\PlatformApi;
use Symfony\Component\HttpFoundation\Response;

/**
 * The store and language of a stop-email page (ops.md §1.3, §1.9; owner, 2026-11-01), on or off: Platform's
 * `store` middleware makes an off store a 404 and takes a form sent into it for a write into a closed store,
 * and neither may stop someone leaving a mailing list. It runs behind the link's signature, so only a link
 * Ops signed gets here. It enters no store and remembers none: this page sets the language it is read in
 * and nothing else about the store is open - every other address of an off store stays a 404.
 */
final readonly class StopEmailStore
{
    public function __construct(
        private PlatformApi $platform,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $code = $route instanceof Route ? $route->parameter('store') : null;
        $locale = $route instanceof Route ? $route->parameter('locale') : null;

        if (! $route instanceof Route || ! is_string($locale) || ! in_array($locale, ['ar', 'en'], true) || ! $this->storeExists($code)) {
            abort(404);
        }

        app()->setLocale($locale);
        $route->forgetParameter('store');
        $route->forgetParameter('locale');

        /** @var Response $response */
        $response = $next($request);

        return $response;
    }

    private function storeExists(mixed $code): bool
    {
        foreach ($this->platform->allStores() as $store) {
            if ($store->code === $code) {
                return true;
            }
        }

        return false;
    }
}
