<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Storefront;

use Illuminate\Contracts\Translation\Translator;
use Modules\Catalog\Presentation\Http\Resource\ShopFrameData;
use Modules\Platform\Public\Contracts\ShopFramePart;
use Modules\Platform\Public\Dto\StoreDto;

/**
 * Catalog's part of every shop page's frame (catalog.md §4.5; owner, 2026-10-07, #9): the category
 * menu for this store, language and viewer — from the never-stale cache (P20, P21) — and the words
 * the menu and the search box say, so a page that names none of Catalog's words still draws them.
 */
final readonly class CatalogFramePart implements ShopFramePart
{
    /** @var list<string> */
    private const array WORDS = ['all_categories', 'categories', 'open_menu', 'search', 'search_placeholder', 'see_all', 'no_suggestions', 'all_of'];

    public function __construct(
        private ShopVisit $visit,
        private Translator $translator,
    ) {}

    public function partFor(StoreDto $store, string $locale): array
    {
        $words = [];

        foreach (self::WORDS as $word) {
            $words[$word] = (string) $this->translator->get("catalog::shop.frame.{$word}", [], $locale);
        }

        return (new ShopFrameData($this->visit->menu($store->storeId(), $locale), $words))->toArray();
    }
}
