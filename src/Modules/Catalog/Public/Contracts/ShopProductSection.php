<?php

declare(strict_types=1);

namespace Modules\Catalog\Public\Contracts;

use Modules\Catalog\Public\Dto\ShopProductVisitDto;

/**
 * **One module's part of a product's page in the shop** (catalog.md amendment 26(b); feedback.md
 * §2.3): Feedback's rating and reviews, its answered questions, the wishlist button — so the page,
 * which is Catalog's, needs no read of the module behind it. Registered with `ShopProductSections`.
 *
 * Asked on **every request** for an available page, outside the page's cache — what it shows changes
 * with what the module decides and with who is looking — and in a **fixed few queries**, however much
 * it holds: the shop's pages keep a budget (P21). Public: it shows what any shopper may see, and what
 * a signed-in customer may see of their own.
 */
interface ShopProductSection
{
    /**
     * What the page draws: plain data only, already in the page's language; null draws nothing.
     *
     * @return array<string, mixed>|null
     */
    public function read(ShopProductVisitDto $visit): ?array;
}
