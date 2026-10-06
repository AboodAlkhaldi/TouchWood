<?php

declare(strict_types=1);

namespace Modules\Catalog\Application\Listing;

/**
 * The listing's rows (catalog.md §5.4), written **inside the transaction of the change that alters
 * them** (§9.3 #20), so a list a shopper reads is never stale; the repair job writes every one again
 * the same way (§3). Each call runs under the products' lock, so it reads each product, its stores'
 * rows and the lists it points at as the change left them.
 *
 * A product has a row in a store, in each language, while a shopper can find it there (§1.4): it is
 * ready, neither its category's nor its brand's deactivation hid it, its brand is active, the store
 * has not marked it "Not available now", and at least one of its variants is switched on there, not
 * archived and not "Not available now". **A row never holds a code** (amendment 5(d)).
 */
interface ListingRows
{
    /**
     * Writes these products' rows again, in every store and both languages, from what they are now.
     *
     * @param  list<string>  $productIds
     */
    public function refresh(array $productIds): void;

    /** Every row written again from nothing — the repair. */
    public function rebuild(): void;
}
