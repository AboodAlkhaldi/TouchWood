<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Controller;

use Illuminate\Http\Request;
use Modules\Platform\Presentation\Http\Middleware\ResolveStore;
use Modules\Platform\Presentation\Http\StorefrontLanguage;
use Modules\Platform\Public\Contracts\PlatformApi;
use Symfony\Component\HttpFoundation\Response;

/**
 * brand.com/ with no store: back to the store remembered in the cookie, otherwise the country
 * page. There is no IP-based detection (Platform spec §1.6). Either way the language is the
 * visitor's remembered one, otherwise the default.
 */
final readonly class ChooseStoreController
{
    public function __construct(
        private PlatformApi $platform,
        private StorefrontLanguage $languages,
    ) {}

    public function __invoke(Request $request): Response
    {
        $locale = $this->languages->for($request);
        $remembered = $request->cookie(ResolveStore::COOKIE);
        $store = is_string($remembered) ? $this->platform->storeByCode($remembered) : null;

        if ($store !== null) {
            // The query string travels on: ad and campaign parameters (utm_source, gclid) must survive.
            return redirect()->route('storefront.home', [...$request->query(), 'store' => $store->code, 'locale' => $locale]);
        }

        app()->setLocale($locale);

        return response()->view('platform::choose-store', [
            'stores' => $this->platform->stores(),
            'locale' => $locale,
        ]);
    }
}
