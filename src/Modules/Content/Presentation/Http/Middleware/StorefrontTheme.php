<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http\Middleware;

use App\Http\StorefrontArea;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Modules\Content\Application\Query\Shop\ContentFrame;
use Modules\Content\Application\Query\Shop\ThemeStyle;
use Modules\Content\Presentation\Http\ShopFrameOfRequest;
use Modules\Platform\Public\Dto\StoreDto;
use Symfony\Component\HttpFoundation\Response;

/**
 * **How the live theme reaches the shell** (content.md §1.8): `content.storefront-theme`, in `StorefrontArea`'s lists
 * after `store`, shares `theme.campaign` (the theme's slug, `data-campaign`) and `theme.style` (`ThemeStyle`'s custom
 * properties, which `app.blade.php` prints) from the frame's one cached read - the shop's pages only, so the panel
 * and an error page drawn outside it stay `base`. `HandleInertiaRequests` is unchanged: it runs before any store is
 * known and knows no module.
 *
 * Both are read when the page is drawn, after the shopper is known, so a preview (a staff view's) is seen. A page that
 * showed a previewed theme keeps no copy anywhere and is kept out of search engines (W5b: `no-store`, `noindex`).
 */
final readonly class StorefrontTheme
{
    public const string ALIAS = StorefrontArea::THEME;

    /** A theme's slug as Content makes them (`ramadan`, `ramadan-2`, `theme-…`); anything else is written as base. */
    private const string SLUG = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

    public function __construct(
        private ShopFrameOfRequest $frames,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $store = $request->attributes->get(StorefrontArea::RESOLVED_STORE);

        if ($store instanceof StoreDto) {
            $theme = function () use ($request, $store): ?array {
                $frame = $this->frames->of($request, $store, app()->getLocale());

                return ContentFrame::theme($frame['kept'], $frame['now']);
            };

            Inertia::share('theme.campaign', static function () use ($theme): string {
                $slug = $theme()['slug'] ?? null;

                return is_string($slug) && preg_match(self::SLUG, $slug) === 1 ? $slug : 'base';
            });
            Inertia::share('theme.style', static function () use ($theme): ?string {
                $live = $theme();
                $style = $live === null ? '' : ThemeStyle::of($live['parts'], app()->getLocale() === 'ar' ? 'rtl' : 'ltr');

                return $style === '' ? null : $style;
            });
        }

        $response = $next($request);

        if (ShopFrameOfRequest::previewed($request)) {
            $response->headers->set('Cache-Control', 'no-store, private');
            $response->headers->set('X-Robots-Tag', 'noindex');
        }

        return $response;
    }
}
