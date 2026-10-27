<?php

declare(strict_types=1);

namespace Modules\Sales\Presentation\Storefront;

use Illuminate\Contracts\Translation\Translator;
use Modules\Platform\Public\Contracts\ShopFramePart;
use Modules\Platform\Public\Dto\StoreDto;

/**
 * Sales's part of every shop page's frame (sales.md §4.1 S3): the header's cart, its words in the page's
 * language so a page that names none of Sales's words still draws it. The same for every shopper, so it may
 * be cached; the count of pieces is the shopper's own and comes apart (§4.2 B13).
 */
final readonly class SalesFramePart implements ShopFramePart
{
    public function __construct(
        private Translator $translator,
    ) {}

    public function partFor(StoreDto $store, string $locale): array
    {
        return ['words' => ['cart' => (string) $this->translator->get('sales::shop_cart.header', [], $locale)]];
    }
}
