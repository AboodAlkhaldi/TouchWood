<?php

declare(strict_types=1);

namespace Modules\Catalog\Public\Contracts;

/**
 * The parts other modules put above a product list in the shop (catalog.md amendment 41(a)), registered in their
 * service providers, as `ShopProductSections` are for a product's page. A category's, a brand's and a search's page
 * carry them as `slots`, keyed by module; Catalog never knows what is in them.
 */
interface ShopListingSlots
{
    /**
     * @param  string  $module  the registering module, e.g. "content"
     * @param  class-string  $slot  a class implementing ShopListingSlot, resolved from the container each time
     *
     * @throws \LogicException for a class that is not a ShopListingSlot, or a module registering twice
     */
    public function register(string $module, string $slot): void;
}
