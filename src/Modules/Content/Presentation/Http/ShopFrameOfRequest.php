<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Http;

use App\Http\StorefrontArea;
use Illuminate\Http\Request;
use Modules\Content\Application\Moments;
use Modules\Content\Application\Query\Shop\ContentFrame;
use Modules\Platform\Public\Contracts\OffStoreViewers;
use Modules\Platform\Public\Dto\StoreDto;

/**
 * **The frame's one cached read, once per shop request** (content.md §1.5): the frame's part, the theme's middleware
 * and a product list's slot all draw from the same copy, kept on the request, so a page pays the cache's two queries
 * once however many of them ask.
 *
 * **Preview Theme** (W5b) reads past the cache here: the presser's signed cookie (`ThemePreviewCookie`) **during a
 * staff view of someone who may see off stores** - an active Super Admin, the only holders of the reserved
 * `content.theme.manage` - asked again on every page, so a role taken away within the cookie's two hours ends it.
 * The shop knows no staff member but through that view; a cookie alone, or a staff view of anyone else, shows
 * nothing (the build's pick: Platform's `OffStoreViewers::mayView`).
 */
final readonly class ShopFrameOfRequest
{
    private const string ATTRIBUTE = 'content.shop-frame';

    public function __construct(
        private ContentFrame $frame,
        private ThemePreviewCookie $cookie,
        private OffStoreViewers $offStoreViewers,
    ) {}

    /**
     * @return array{kept: array<string, mixed>, preview: string|null, now: \DateTimeImmutable}
     */
    public function of(Request $request, StoreDto $store, string $locale): array
    {
        $memo = $request->attributes->get(self::ATTRIBUTE);
        $key = "{$store->id}:{$locale}";

        if (is_array($memo) && ($memo['key'] ?? null) === $key) {
            /** @var array{kept: array<string, mixed>, preview: string|null, now: \DateTimeImmutable} */
            return $memo['frame'];
        }

        $kept = $this->frame->kept($store, $locale);
        $preview = null;
        $themeId = $this->cookie->shopTheme($request);

        if ($themeId !== null && $request->attributes->get(StorefrontArea::STAFF_VIEW) === true && $this->offStoreViewers->mayView($store->storeId())) {
            ['kept' => $kept, 'name' => $preview] = $this->frame->withPreview($kept, $store, $locale, $themeId);
        }

        $frame = ['kept' => $kept, 'preview' => $preview, 'now' => Moments::now()];
        $request->attributes->set(self::ATTRIBUTE, ['key' => $key, 'frame' => $frame]);

        return $frame;
    }

    /** Whether this request showed a previewed theme: its answer then keeps no copy and stays out of search engines. */
    public static function previewed(Request $request): bool
    {
        $memo = $request->attributes->get(self::ATTRIBUTE);

        return is_array($memo) && is_array($memo['frame'] ?? null) && ($memo['frame']['preview'] ?? null) !== null;
    }
}
