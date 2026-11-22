<?php

declare(strict_types=1);

namespace Modules\Catalog\Public\Contracts;

use Modules\Platform\Public\Dto\StoreDto;

/**
 * **One module's part of a product's Stores tab** (catalog.md amendment 23(e); pricing.md §9.3 S3): the
 * prices, the stock - what the module keeps about the product in each store, drawn inside each store's
 * card, so the owner's "prices and stock on the product page" (2026-10-18) needs no read of Pricing or
 * Inventory by Catalog. Registered with `ProductStoreSections`.
 *
 * **One read for every card on the tab**: the tab shows a card per store the reader covers, and the
 * section answers them all at once - a fixed few queries however many stores, so the tab keeps its
 * budget (frontend.md §5). It is asked only when the tab is opened, as the person reading it: it checks
 * its own jobs, per store, and leaves out a store whose part the reader may not see.
 */
interface ProductStoreSection
{
    /**
     * The page component that draws it, by its path under `resources/js/sections/` without the
     * extension - "Pricing/ProductPrices" - given each card's data as `data`.
     */
    public function component(): string;

    /**
     * What each store's card draws: plain data only, already in the page's language.
     *
     * @param  list<string>  $variantIds  the product's sizes not archived, in its order
     * @param  list<StoreDto>  $stores  the tab's stores, in the cards' order
     * @return array<string, array<string, mixed>> store id => its card's part; a store left out draws none
     */
    public function read(string $productId, array $variantIds, array $stores): array;
}
