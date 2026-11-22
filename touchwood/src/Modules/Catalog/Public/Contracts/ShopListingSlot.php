<?php

declare(strict_types=1);

namespace Modules\Catalog\Public\Contracts;

use Modules\Catalog\Public\Dto\ShopListingVisitDto;

/**
 * What a module puts above a product list in the shop (catalog.md amendment 41(a)): Content's smart bar and listing
 * banner. Asked on every category, brand and search page outside the page's cache, so it answers in a fixed few
 * queries, from its own cache where it can.
 */
interface ShopListingSlot
{
    /**
     * Plain data already in the page's language, or null for nothing to draw above this list.
     *
     * @return array<string, mixed>|null
     */
    public function read(ShopListingVisitDto $visit): ?array;
}
