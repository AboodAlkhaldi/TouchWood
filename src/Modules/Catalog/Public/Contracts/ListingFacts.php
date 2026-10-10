<?php

declare(strict_types=1);

namespace Modules\Catalog\Public\Contracts;

use Modules\Catalog\Public\Dto\ListingPrice;
use Shared\Domain\ValueObject\StoreId;

/**
 * The facts Catalog's listing needs but does not own, **pushed in by the modules that own them**,
 * inside their own transaction, so a list is never stale (catalog.md §2.2): whether a variant can be
 * ordered now and whether it is ending soon (Inventory, stage 5), the price shown (Pricing, stage 5),
 * the sales rank (Sales, stage 6). **Bound with amendment 16(i)**: each fact is kept in Catalog's own
 * table (`store_variant_facts`); the cards read them with the shop's pages (P22). Until then every
 * listed product is orderable as §1.3 says, with no price and no rank; Sales' ranks are received
 * from stage 6.
 *
 * **For stage 5:** every change rewrites a product's listing rows from Catalog's own tables
 * (`ListingRows`), the price and the rank with them. A fact pushed here must therefore be kept where
 * that writer reads it — its own table — never only in the listing's columns, which the next change
 * to the product would write over.
 */
interface ListingFacts
{
    /**
     * @param  list<string>  $variantIds
     */
    public function orderable(StoreId $store, array $variantIds, bool $orderable): void;

    /**
     * Inventory's "last pieces" (amendment 16(h)): a card shows them while a size its viewer can buy
     * and order now is ending soon.
     *
     * @param  list<string>  $variantIds
     */
    public function endingSoon(StoreId $store, array $variantIds, bool $endingSoon): void;

    /**
     * @param  array<string, ListingPrice|null>  $prices  variant id => its price there, or none (not on sale there)
     */
    public function prices(StoreId $store, array $prices): void;

    /**
     * @param  array<string, int>  $ranks  product id => its rank there, 1 the best-selling
     */
    public function salesRanks(StoreId $store, array $ranks): void;
}
