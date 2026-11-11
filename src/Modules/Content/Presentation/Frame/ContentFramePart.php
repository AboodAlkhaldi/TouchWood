<?php

declare(strict_types=1);

namespace Modules\Content\Presentation\Frame;

use App\Http\StorefrontArea;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Http\Request;
use Modules\Content\Application\Query\Shop\ContentFrame;
use Modules\Content\Application\Query\Shop\ThemeStyle;
use Modules\Content\Domain\Model\ThemePartKind;
use Modules\Content\Presentation\Http\ShopFrameOfRequest;
use Modules\Platform\Public\Contracts\ShopFramePart;
use Modules\Platform\Public\Dto\StoreDto;

/**
 * Content's part of every shop page's frame (`frame.content`, content.md §1.5), from the frame's one cached read:
 *
 * - **the announcement bar**, dressed by a live theme's part 1, `art` when its strip has artwork;
 * - **the footer** per store;
 * - **the pop-up** (§1.10), only on the pages a shopper browses - home, the catalogue's pages and Content's own
 *   (the build's pick: a list of where it may show, so no page added later puts one between a customer and paying) -
 *   and never in a staff view. Once per visitor, its delay and the phone's sheet are the browser's;
 * - **the live theme's parts drawn as things** (§1.8): the badge's words, the flag's and the ribbon's WebP (transparent,
 *   part 6), the cart message (part 9), and whether the footer has artwork (part 10). The rest are `theme.style`'s;
 * - **a preview's line** (W5b): the previewed theme's name, for the staff view's line and Stop Preview;
 * - **the frame's own words** (`content::shop_frame`): the phone's tab bar and drawer.
 */
final readonly class ContentFramePart implements ShopFramePart
{
    /** @var list<string> the pages a pop-up may show on */
    public const array POPUP_PAGES = ['storefront.home', 'storefront.catalog.*', 'storefront.content.*'];

    public function __construct(
        private ShopFrameOfRequest $frames,
        private ContentFrame $frame,
        private Translator $translator,
        private Request $request,
    ) {}

    public function partFor(StoreDto $store, string $locale): array
    {
        ['kept' => $kept, 'preview' => $preview, 'now' => $now] = $this->frames->of($this->request, $store, $locale);
        $drawn = $this->frame->in($kept, $now);
        $theme = ContentFrame::theme($kept, $now);
        $staffView = $this->request->attributes->get(StorefrontArea::STAFF_VIEW) === true;

        return [
            'bar' => $drawn['bar'],
            'footer' => $drawn['footer'],
            'popup' => $staffView || ! $this->request->routeIs(...self::POPUP_PAGES) ? null : $drawn['popup'],
            'theme' => $theme === null ? null : self::theme($theme['parts']),
            'preview' => $preview,
            'words' => (array) $this->translator->get('content::shop_frame', [], $locale),
        ];
    }

    /**
     * @param  array<string, array{text: string|null, link: string|null, extras: array<string, string>, images: array<string, array<string, array<string, string>>>}>  $parts
     * @return array{badge: string|null, flag: string|null, ribbon: string|null, cart: array{text: string, link: string|null}|null, footerArt: bool}
     */
    private static function theme(array $parts): array
    {
        $cart = $parts[ThemePartKind::Cart->value] ?? null;
        $flag = $parts[ThemePartKind::Flag->value]['images'] ?? [];

        return [
            'badge' => $parts[ThemePartKind::Badge->value]['text'] ?? null,
            // A 96 px flag from the 200 px size, a 400 px ribbon from the 600 px one: WebP alone, transparent (§1.8).
            'flag' => self::webp($flag['FLAG:any'] ?? [], 'thumb'),
            'ribbon' => self::webp($flag['RIBBON:any'] ?? [], 'card'),
            'cart' => $cart !== null && $cart['text'] !== null ? ['text' => $cart['text'], 'link' => $cart['link']] : null,
            'footerArt' => ($parts[ThemePartKind::Footer->value]['images'] ?? []) !== [],
        ];
    }

    /** @param  array<string, array<string, string>>  $variants */
    private static function webp(array $variants, string $size): ?string
    {
        $url = $variants[$size]['webp'] ?? null;

        return is_string($url) && ThemeStyle::safe($url) ? $url : null;
    }
}
