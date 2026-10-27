<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Storefront;

use Modules\Catalog\Public\Contracts\ShopProductSection;
use Modules\Catalog\Public\Dto\ShopProductVisitDto;

/**
 * Sales's part of a product's page (sales.md §4.1 S2): Add to Cart, on an available page only - a "Not available
 * now" page has none (catalog.md §1.4). The page already says how each size is sold to this viewer (catalog.md
 * P17); this says the cart is offered and carries Sales's words, in no query. Whether a size may be added -
 * its stock, its account's rules - the cart's own command answers when it is pressed.
 */
final readonly class CartSection implements ShopProductSection
{
    /** @return array{offered: true} */
    public function read(ShopProductVisitDto $visit): array
    {
        return ['offered' => true];
    }
}
