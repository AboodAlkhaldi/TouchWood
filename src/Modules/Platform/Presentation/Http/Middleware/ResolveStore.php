<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Middleware;

use App\Http\StorefrontArea;
use Closure;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Modules\Platform\Infrastructure\LaravelStoreContext;
use Modules\Platform\Presentation\Http\StorefrontLanguage;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\StoreDto;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves brand.com/{store}/{locale}/... to exactly one store and one language (Platform spec
 * §1.6; the language segment is the owner's decision of 2026-09-18).
 *
 * An unknown store is a 404; the {locale} route pattern only lets supported languages through. The
 * store becomes the store context and the language the app locale for the rest of the request —
 * pages, validation and error messages follow it. Both fill their segment in every generated URL
 * and are remembered in cookies for brand.com/ and brand.com/{store}.
 * Other modules attach this to their storefront routes with the "store" middleware alias.
 */
final readonly class ResolveStore
{
    public const string ALIAS = StorefrontArea::STORE;

    public const string COOKIE = 'tw_store';

    public const string REQUEST_ATTRIBUTE = 'store';

    private const int COOKIE_MINUTES = 60 * 24 * 365;

    public function __construct(
        private PlatformApi $platform,
        private LaravelStoreContext $context,
        private UrlGenerator $url,
        private StorefrontLanguage $languages,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $code = $route instanceof Route ? $route->parameter('store') : null;
        $locale = $route instanceof Route ? $route->parameter('locale') : null;
        $store = is_string($code) ? $this->platform->storeByCode($code) : null;

        if (! $route instanceof Route || $store === null || ! is_string($locale) || ! $this->languages->isSupported($locale)) {
            abort(404);
        }

        $this->context->enter($store->storeId());
        app()->setLocale($locale);
        $route->forgetParameter('store');
        $route->forgetParameter('locale');
        $this->url->defaults(['store' => $store->code, 'locale' => $locale]);
        $request->attributes->set(self::REQUEST_ATTRIBUTE, $store);

        /** @var Response $response */
        $response = $next($request);
        $response->headers->setCookie(cookie(self::COOKIE, $store->code, self::COOKIE_MINUTES));
        $response->headers->setCookie(cookie(StorefrontLanguage::COOKIE, $locale, self::COOKIE_MINUTES));

        return $response;
    }

    public static function current(Request $request): StoreDto
    {
        $store = self::currentOrNull($request);

        if ($store === null) {
            abort(404);
        }

        return $store;
    }

    /**
     * The store, or nothing, for the one page of the shop that may be read without one: the
     * country page, where asking is how we learn the visitor has not chosen yet.
     */
    public static function currentOrNull(Request $request): ?StoreDto
    {
        $store = $request->attributes->get(self::REQUEST_ATTRIBUTE);

        return $store instanceof StoreDto ? $store : null;
    }
}
