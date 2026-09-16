<?php

namespace Modules\Platform\Presentation\Http\Controller;

use Illuminate\Http\Request;
use Modules\Platform\Presentation\Http\Middleware\ResolveStore;
use Modules\Platform\Public\Contracts\PlatformApi;
use Symfony\Component\HttpFoundation\Response;

/**
 * brand.com/ with no store: back to the store remembered in the cookie, otherwise the country
 * page. There is no IP-based detection (Platform spec §1.6).
 */
final readonly class ChooseStoreController
{
    public function __construct(
        private PlatformApi $platform,
    ) {}

    public function __invoke(Request $request): Response
    {
        $remembered = $request->cookie(ResolveStore::COOKIE);
        $store = is_string($remembered) ? $this->platform->storeByCode($remembered) : null;

        if ($store !== null) {
            return redirect()->route('storefront.home', ['store' => $store->code]);
        }

        return response()->view('platform::choose-store', [
            'stores' => $this->platform->stores(),
            'locale' => app()->getLocale(),
        ]);
    }
}
