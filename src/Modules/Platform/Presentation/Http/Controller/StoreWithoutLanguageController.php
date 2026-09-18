<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Controller;

use Illuminate\Http\Request;
use Modules\Platform\Presentation\Http\StorefrontLanguage;
use Modules\Platform\Public\Contracts\PlatformApi;
use Symfony\Component\HttpFoundation\Response;

/**
 * brand.com/{store} with no language: on to the visitor's remembered language, otherwise the
 * default (owner's decision, 2026-09-18). A temporary redirect, because the answer depends on the
 * visitor's cookie.
 */
final readonly class StoreWithoutLanguageController
{
    public function __construct(
        private PlatformApi $platform,
        private StorefrontLanguage $languages,
    ) {}

    public function __invoke(Request $request, string $store): Response
    {
        if ($this->platform->storeByCode($store) === null) {
            abort(404);
        }

        // The query string travels on: ad and campaign parameters (utm_source, gclid) must survive.
        return redirect()->route('storefront.home', [...$request->query(), 'store' => $store, 'locale' => $this->languages->for($request)]);
    }
}
