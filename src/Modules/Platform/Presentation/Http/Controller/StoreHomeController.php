<?php

declare(strict_types=1);

namespace Modules\Platform\Presentation\Http\Controller;

use Illuminate\Http\Request;
use Modules\Platform\Presentation\Http\Middleware\ResolveStore;
use Symfony\Component\HttpFoundation\Response;

/**
 * Placeholder for brand.com/{store} until the Content module builds the real homepage.
 * It exists so store resolution has a page to land on.
 */
final readonly class StoreHomeController
{
    public function __invoke(Request $request): Response
    {
        return response()->view('platform::store-home', [
            'store' => ResolveStore::current($request),
            'locale' => app()->getLocale(),
        ]);
    }
}
