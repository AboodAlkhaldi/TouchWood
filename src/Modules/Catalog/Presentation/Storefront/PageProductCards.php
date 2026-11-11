<?php

declare(strict_types=1);

namespace Modules\Catalog\Presentation\Storefront;

use Modules\Catalog\Public\Contracts\ShopProductCards;
use Shared\Domain\ValueObject\StoreId;

/** `ShopProductCards` as the shop's own pages draw cards (amendment 46(c)). */
final readonly class PageProductCards implements ShopProductCards
{
    /** At most as many cards as a page of the shop's lists holds. */
    public const int MAX = 100;

    /** Products a page is chosen among, at most (a campaign's reach, pricing.md §9.6 #8). */
    public const int MOST_AMONG = 20000;

    public function __construct(
        private ShopPages $pages,
    ) {}

    public function read(StoreId $store, string $locale, array $productIds): array
    {
        return $this->pages->cardsOf($store, $locale, array_slice($productIds, 0, self::MAX));
    }

    public function page(StoreId $store, string $locale, array $productIds, ?string $after, int $limit): array
    {
        return $this->pages->cardsAmong($store, $locale, array_slice($productIds, 0, self::MOST_AMONG), $after, min(max($limit, 1), self::MAX));
    }
}
