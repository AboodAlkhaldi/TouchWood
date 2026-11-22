<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Controller;

use App\Http\Page;
use App\Http\StorefrontArea;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Platform\Presentation\Http\Middleware\ResolveStore;
use Modules\Platform\Presentation\Http\Resource\ChooseStorePage;
use Modules\Platform\Presentation\Http\Resource\StoreChoiceRow;
use Modules\Platform\Public\Contracts\PlatformApi;
use Modules\Platform\Public\Dto\StoreDto;

/**
 * brand.com/ with no store: back to the store remembered in the cookie; otherwise **on to the base store's home, a
 * temporary redirect (302)**, for a first visit and for Google alike (content.md §1.14, Q20 (a), owner 2026-11-10;
 * replaces the country page here). A 302, never a 301: a returning visitor goes elsewhere, and a browser keeps a 301
 * (plan K12). The shopper changes store with the header's country switch. There is no IP-based detection (Platform
 * spec §1.6). Either way the language is the visitor's remembered one, otherwise the default, Arabic.
 *
 * The base store is always on (`stores_base_always_active`); should none be marked, the first store that is on takes
 * its place, and with no store on at all the country page, as before, offering none.
 */
final readonly class ChooseStoreController
{
    /** @var list<string> */
    private const array WORDS = [...StorefrontArea::WORDS];

    public function __construct(
        private PlatformApi $platform,
        private Application $app,
        private Page $page,
    ) {}

    public function __invoke(Request $request): Response|RedirectResponse
    {
        // Already settled by ShareStorefront, which reads the language this visitor read last -
        // asking a second time here is how the page and the choices drift apart.
        $locale = $this->app->getLocale();
        $remembered = $request->cookie(ResolveStore::COOKIE);
        $store = is_string($remembered) ? $this->platform->storeByCode($remembered) : null;

        $stores = $store === null ? $this->platform->stores() : [];
        $store ??= array_values(array_filter($stores, static fn (StoreDto $candidate): bool => $candidate->isBase))[0] ?? $stores[0] ?? null;

        if ($store !== null) {
            // The query string travels on: ad and campaign parameters (utm_source, gclid) must survive.
            return redirect()->route('storefront.home', [...StoreWithoutLanguageController::namedQuery($request), 'store' => $store->code, 'locale' => $locale], 302);
        }

        $choices = array_map(fn (StoreDto $store): StoreChoiceRow => new StoreChoiceRow(
            $store->code,
            $store->name->in($locale),
            $store->countryCode,
            $store->currencyCode,
            $store->currencySymbol($locale),
            route('storefront.home', ['store' => $store->code, 'locale' => $locale], false),
        ), $stores);

        return $this->page->render('Platform/Storefront/ChooseStore', (new ChooseStorePage($choices))->toArray(), self::WORDS);
    }
}
