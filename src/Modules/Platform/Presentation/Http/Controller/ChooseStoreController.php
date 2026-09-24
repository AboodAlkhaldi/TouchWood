<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Controller;

use App\Http\Page;
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
 * brand.com/ with no store: back to the store remembered in the cookie, otherwise the country
 * page. There is no IP-based detection (Platform spec §1.6). Either way the language is the
 * visitor's remembered one, otherwise the default.
 */
final readonly class ChooseStoreController
{
    /** @var list<string> */
    private const array WORDS = ['platform::stores', 'admin'];

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

        if ($store !== null) {
            // The query string travels on: ad and campaign parameters (utm_source, gclid) must survive.
            return redirect()->route('storefront.home', [...StoreWithoutLanguageController::namedQuery($request), 'store' => $store->code, 'locale' => $locale]);
        }

        $choices = array_map(fn (StoreDto $store): StoreChoiceRow => new StoreChoiceRow(
            $store->code,
            $store->name->in($locale),
            $store->countryCode,
            $store->currencyCode,
            $store->currencySymbol($locale),
            route('storefront.home', ['store' => $store->code, 'locale' => $locale], false),
        ), $this->platform->stores());

        return $this->page->render('Platform/Storefront/ChooseStore', (new ChooseStorePage($choices))->toArray(), self::WORDS);
    }
}
