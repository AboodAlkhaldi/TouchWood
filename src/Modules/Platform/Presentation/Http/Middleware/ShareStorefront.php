<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Middleware;

use App\Http\StorefrontArea;
use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\Platform\Presentation\Http\StorefrontLanguage;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\StoreDto;
use Symfony\Component\HttpFoundation\Response;

/**
 * Which shop a page belongs to, and the language it is read in (frontend.md §2.3).
 *
 * Platform's half of what every shop page carries; Access shares the other half - whoever is
 * signed in. Split that way because which store a URL names, and which languages the shop can be
 * read in, are Platform's subject, and Access may not reach into Platform for them.
 *
 * **The address decides the language, not the cookie.** The application-wide Inertia middleware
 * shares a language before any route middleware has run, and it prefers this browser's remembered
 * one - so a visitor whose last language was Arabic was handed /sa/en with lang="ar" and the whole
 * page turned around, in English (found by running it, 2026-09-24). By the time this runs the URL
 * has been read, so what it says here is the last word.
 *
 * On the country page there is no language in the address, because there is no store either: there
 * the remembered one is right, and it is what this asks for.
 */
final readonly class ShareStorefront
{
    public const string ALIAS = StorefrontArea::SHOP;

    public function __construct(
        private PlatformApi $platform,
        private StorefrontLanguage $languages,
        private Application $app,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $store = ResolveStore::currentOrNull($request);

        // Under a store the language is the second segment, which ResolveStore has already put on
        // the application; without one it is whatever this visitor read last.
        $locale = $store === null ? $this->languages->for($request) : $this->app->getLocale();
        $this->app->setLocale($locale);

        Inertia::share([
            'locale' => $locale,
            'direction' => $locale === 'ar' ? 'rtl' : 'ltr',
            // "shop", not "store": the panel already shares a "store", and it is a different thing
            // - which store a staff member is working in, with the ones they may switch to. One
            // name for two shapes is how a screen ends up reading the wrong one.
            'shop' => fn (): ?array => $this->shop($store, $locale),
        ]);

        return $next($request);
    }

    /**
     * The store this page belongs to, and the others somebody may switch to.
     *
     * Null on the country page, which is the one shop page that belongs to no store: it exists
     * precisely because the visitor has not chosen one.
     *
     * @return array<string, mixed>|null
     */
    private function shop(?StoreDto $current, string $locale): ?array
    {
        if ($current === null) {
            return null;
        }

        $available = [];

        foreach ($this->platform->stores() as $store) {
            $available[] = [
                'code' => $store->code,
                'name' => $store->name->in($locale),
                'current' => $store->code === $current->code,
            ];
        }

        return [
            'code' => $current->code,
            'name' => $current->name->in($locale),
            'currency' => $current->currencyCode,
            'symbol' => $current->currencySymbol($locale),
            'available' => $available,
            'languages' => $this->languages->all(),
        ];
    }
}
