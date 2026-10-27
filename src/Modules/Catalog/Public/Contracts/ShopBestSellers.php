<?php

declare(strict_types=1);

namespace Modules\Catalog\Public\Contracts;

use Shared\Domain\ValueObject\StoreId;

/**
 * The store's best sellers as shop cards (catalog.md amendment 36), for a page another module draws — an
 * empty cart's suggestions (sales.md §4.2 B14). The cards are the ones the shop's lists draw
 * (`ShopCardData`: prices, labels, stars), for the shopper looking, only what they can order here, of
 * brands shown in default listings, best-selling first, then newest; with `cardFacts`, what the modules
 * add to them (the Save heart), as on every list page. Ready for the page as it is: the amounts are
 * Platform's `MoneyData`, written out with the rest when the page is sent.
 */
interface ShopBestSellers
{
    /**
     * A language but Arabic or English is refused, as on the shop's own pages.
     *
     * @param  int  $limit  1 to 20
     * @return array{cards: list<array<string, mixed>>, cardFacts: array<string, array<string, array<string, mixed>>>}
     */
    public function read(StoreId $store, string $locale, int $limit): array;
}
