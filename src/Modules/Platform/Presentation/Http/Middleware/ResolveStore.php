<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Middleware;

use App\Http\StorefrontArea;
use Closure;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Modules\Platform\Application\Query\StoreDirectory;
use Modules\Platform\Application\Routing\InMemoryOffStoreViewers;
use Modules\Platform\Infrastructure\LaravelStoreContext;
use Modules\Platform\Presentation\Http\StorefrontLanguage;
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
 *
 * An off store is a 404, exactly as an unknown code (§1.6) - unless a module's off-store viewer
 * says this request may see it, and it only reads: a staff member viewing the shop from the panel
 * (§2.7; access.md §1.11). Nobody else learns the store exists.
 */
final readonly class ResolveStore
{
    public const string ALIAS = StorefrontArea::STORE;

    public const string COOKIE = 'tw_store';

    public const string REQUEST_ATTRIBUTE = 'store';

    private const int COOKIE_MINUTES = 60 * 24 * 365;

    public function __construct(
        private StoreDirectory $directory,
        private InMemoryOffStoreViewers $offStoreViewers,
        private LaravelStoreContext $context,
        private UrlGenerator $url,
        private StorefrontLanguage $languages,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        $code = $route instanceof Route ? $route->parameter('store') : null;
        $locale = $route instanceof Route ? $route->parameter('locale') : null;
        $store = is_string($code) ? $this->store($code, $request) : null;

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

    /**
     * The store a code names, as PlatformApi::storeByCode() answers it - but for a viewer of an off
     * one, **to look only**: a request that reads (GET, HEAD). A form sent into a closed store would
     * write into it - a customer registered in a store nobody can open (the review of P6).
     */
    private function store(string $code, Request $request): ?StoreDto
    {
        $store = $this->directory->storeByCode($code);

        if ($store === null || $store->isActive) {
            return $store;
        }

        return $request->isMethodSafe() && $this->offStoreViewers->mayView($store->storeId()) ? $store : null;
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
