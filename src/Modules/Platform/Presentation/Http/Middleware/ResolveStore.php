<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Middleware;

use Closure;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Modules\Platform\Infrastructure\LaravelStoreContext;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\StoreDto;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves brand.com/{store}/... to exactly one store (Platform spec §1.6).
 *
 * An unknown code is a 404. A known one becomes the store context for the rest of the request,
 * fills {store} in every generated URL, and is remembered in a cookie for brand.com/.
 * Other modules attach it to their storefront routes with the "store" middleware alias.
 */
final readonly class ResolveStore
{
    public const string ALIAS = 'store';

    public const string COOKIE = 'tw_store';

    public const string REQUEST_ATTRIBUTE = 'store';

    private const int COOKIE_MINUTES = 60 * 24 * 365;

    public function __construct(
        private PlatformApi $platform,
        private LaravelStoreContext $context,
        private UrlGenerator $url,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $code = $route instanceof Route ? $route->parameter('store') : null;
        $store = is_string($code) ? $this->platform->storeByCode($code) : null;

        if (! $route instanceof Route || $store === null) {
            abort(404);
        }

        $this->context->enter($store->storeId());
        $route->forgetParameter('store');
        $this->url->defaults(['store' => $store->code]);
        $request->attributes->set(self::REQUEST_ATTRIBUTE, $store);

        /** @var Response $response */
        $response = $next($request);
        $response->headers->setCookie(cookie(self::COOKIE, $store->code, self::COOKIE_MINUTES));

        return $response;
    }

    public static function current(Request $request): StoreDto
    {
        $store = $request->attributes->get(self::REQUEST_ATTRIBUTE);

        if (! $store instanceof StoreDto) {
            abort(404);
        }

        return $store;
    }
}
