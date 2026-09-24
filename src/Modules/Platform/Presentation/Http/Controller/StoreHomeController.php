<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Controller;

use App\Http\Page;
use Illuminate\Http\Request;
use Inertia\Response;
use Modules\Platform\Presentation\Http\Middleware\ResolveStore;
use Modules\Platform\Presentation\Http\Resource\StoreHomePage;

/**
 * Placeholder for brand.com/{store} until the Content module builds the real homepage.
 * It exists so store resolution has a page to land on.
 */
final readonly class StoreHomeController
{
    /** @var list<string> */
    private const array WORDS = ['platform::stores', 'admin'];

    public function __construct(
        private Page $page,
    ) {}

    public function __invoke(Request $request): Response
    {
        $store = ResolveStore::current($request);
        $locale = app()->getLocale();

        return $this->page->render('Platform/Storefront/Home', (new StoreHomePage(
            $store->name->in($locale),
            $store->currencyCode,
            $store->currencySymbol($locale),
        ))->toArray(), self::WORDS);
    }
}
