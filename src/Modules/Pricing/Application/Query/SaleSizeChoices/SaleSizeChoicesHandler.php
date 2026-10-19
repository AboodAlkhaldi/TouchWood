<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\SaleSizeChoices;

use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Dto\FoundVariantDto;
use Modules\Pricing\Application\Prices\PriceViews;
use Modules\Pricing\Application\Prices\ReadAccess;
use Modules\Pricing\Application\PricingPermissions;
use Modules\Pricing\Domain\Exception\InvalidPricingInput;
use Modules\Pricing\Domain\Exception\StoreOff;
use Modules\Pricing\Domain\Repository\RetailPrices;
use Shared\Application\Unauthorized;

/**
 * **A product's sizes for an offer** (`SaleSizeChoices`, pricing.md §4.3.3 P2 "Sizes"; stage 5's screens step
 * 3), for whoever may add an offer in the store: the product's sizes switched on there, none archived, in the
 * product's order (Catalog's `searchVariants` with `only`), each with its retail price. Four queries besides
 * the asking, however many sizes. A read: nothing written.
 */
final readonly class SaleSizeChoicesHandler
{
    /** More sizes than a product has: Catalog pages at most 100. */
    private const int MOST = 100;

    public function __construct(
        private ReadAccess $access,
        private CatalogApi $catalog,
        private RetailPrices $retailPrices,
        private PriceViews $views,
    ) {}

    /**
     * @return list<SaleSizeChoice>
     *
     * @throws Unauthorized|InvalidPricingInput|StoreOff
     */
    public function handle(SaleSizeChoices $query): array
    {
        $store = $this->access->authorize($query->storeId, [PricingPermissions::PRICE_EDIT]);
        $storeId = $store->storeId();
        $ids = $this->catalog->variantIdsOf(strtolower(trim($query->productId)));

        if ($ids === []) {
            return [];
        }

        $found = array_values(array_filter(
            $this->catalog->searchVariants($storeId, null, only: $ids, limit: self::MOST, notArchived: true)->rows,
            static fn (FoundVariantDto $row): bool => $row->switchedOn,
        ));
        $retail = $this->retailPrices->forVariants($storeId, array_map(static fn (FoundVariantDto $row): string => $row->variantId, $found));

        return array_map(fn (FoundVariantDto $row): SaleSizeChoice => new SaleSizeChoice(
            $row->variantId,
            $this->views->sizeName($row->values),
            $row->code,
            isset($retail[$row->variantId]) ? $this->views->money($retail[$row->variantId], $store) : null,
        ), $found);
    }
}
