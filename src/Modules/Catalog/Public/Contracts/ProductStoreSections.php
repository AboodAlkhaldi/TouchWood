<?php

declare(strict_types=1);

namespace Modules\Catalog\Public\Contracts;

/**
 * **The product page's store sections** (catalog.md amendment 23(e)): a registry, as `ImportSections` is,
 * where a module above Catalog registers the class that reads its part of a product's Stores tab -
 * Pricing its prices, Inventory its stock (stage 5's screens). Each registered section is drawn in every
 * store card on the tab, in the order registered, replacing the card's line "Prices and stock come with
 * Pricing and Inventory"; with none registered the line stays.
 */
interface ProductStoreSections
{
    /**
     * @param  class-string  $section  a class implementing ProductStoreSection, resolved from the container
     *                                 when the tab is read
     *
     * @throws \LogicException for a class that is not a ProductStoreSection, or one registered twice
     */
    public function register(string $module, string $section): void;
}
