<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\NeedsAPrice;

use Modules\Catalog\Public\Dto\FoundVariantDto;
use Modules\Pricing\Application\Prices\PriceViews;
use Modules\Pricing\Application\Prices\ReadAccess;
use Modules\Pricing\Application\Prices\UnpricedSizes;
use Modules\Pricing\Application\PricingPermissions;
use Modules\Pricing\Domain\Exception\InvalidPricingInput;
use Modules\Pricing\Domain\Exception\StoreOff;
use Shared\Application\Unauthorized;

/**
 * **Needs a Price** (pricing.md §1.9, §3, §4.3.3 P5), under **Edit Prices and Sales** in that store (owner,
 * 2026-10-08): every size switched on with no retail price, none archived, 50 at a time with the total
 * (Q14) - the same reader its kept count uses. Three queries besides the asking, however many sizes. A
 * read: no lock, nothing written.
 */
final readonly class NeedsAPriceHandler
{
    public function __construct(
        private ReadAccess $access,
        private UnpricedSizes $unpriced,
        private PriceViews $views,
    ) {}

    /**
     * @throws Unauthorized|InvalidPricingInput|StoreOff
     */
    public function handle(NeedsAPrice $query): UnpricedList
    {
        $store = $this->access->authorize($query->storeId, [PricingPermissions::PRICE_EDIT])->storeId();
        $found = $this->unpriced->page($store, $query->search, $query->after, NeedsAPrice::PER_PAGE);

        return new UnpricedList(
            array_map(fn (FoundVariantDto $row) => $this->views->size($row), $found->rows),
            $found->next,
            $found->total,
        );
    }
}
