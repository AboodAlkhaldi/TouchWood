<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Controller;

use Illuminate\Http\Request;
use Modules\Platform\Presentation\Http\InMemoryUnmatchedAddresses;
use Modules\Platform\Presentation\Http\StorefrontLanguage;
use Modules\Platform\Public\Contracts\PlatformApi;
use Symfony\Component\HttpFoundation\Response;

/**
 * brand.com/{store} with no language: on to the visitor's remembered language, otherwise the
 * default (owner's decision, 2026-09-18). A temporary redirect, because the answer depends on the
 * visitor's cookie.
 *
 * A single word that names no store - `/blog`, `/contact`, which `{store}`'s pattern takes - is first
 * offered to the modules' answers for an address nobody answers (content.md §2.3 row L2: the old site's
 * addresses), then not found as before. That answer runs inside the shop's session middleware and is given
 * the path alone, so it reads and writes nothing of the session.
 */
final readonly class StoreWithoutLanguageController
{
    public function __construct(
        private PlatformApi $platform,
        private StorefrontLanguage $languages,
    ) {}

    public function __invoke(Request $request, string $store, InMemoryUnmatchedAddresses $addresses): Response
    {
        if ($this->platform->storeByCode($store) === null) {
            return $addresses->answerRequest($request) ?? abort(404);
        }

        // The query string travels on: ad and campaign parameters (utm_source, gclid) must survive.
        // Named parameters only: Laravel treats a numeric key as a route parameter.
        return redirect()->route('storefront.home', [...self::namedQuery($request), 'store' => $store, 'locale' => $this->languages->for($request)]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function namedQuery(Request $request): array
    {
        return array_filter($request->query(), is_string(...), ARRAY_FILTER_USE_KEY);
    }
}
