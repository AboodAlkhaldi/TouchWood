<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\ListWholesale;

use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Dto\FoundVariantDto;
use Modules\Pricing\Application\Prices\PriceViews;
use Modules\Pricing\Application\Prices\ReadAccess;
use Modules\Pricing\Domain\Exception\InvalidPricingInput;
use Modules\Pricing\Domain\Exception\StoreOff;
use Modules\Pricing\Domain\Repository\RetailPrices;
use Modules\Pricing\Domain\Repository\WholesaleBands;
use Shared\Application\Unauthorized;

/**
 * **The Wholesale Prices screen** (`ListWholesale`, pricing.md §4.3.3 P4), for whoever holds any of
 * Pricing's three jobs in the store: the sizes with bands there, in Catalog's order (`searchVariants` with
 * `only`), each with its retail price, bands and the product's wholesale terms (`wholesaleOf`). A fixed
 * six queries however many rows. A read: no lock, nothing written.
 */
final readonly class ListWholesaleHandler
{
    public function __construct(
        private ReadAccess $access,
        private CatalogApi $catalog,
        private RetailPrices $retailPrices,
        private WholesaleBands $bands,
        private PriceViews $views,
    ) {}

    /**
     * @throws Unauthorized|InvalidPricingInput|StoreOff
     */
    public function handle(ListWholesale $query): WholesaleList
    {
        $store = $this->access->authorize($query->storeId);
        $storeId = $store->storeId();
        $found = $this->catalog->searchVariants($storeId, $query->search, $this->bands->variantsWithBands($storeId), after: $query->after, limit: ListWholesale::PER_PAGE);
        $ids = array_map(static fn (FoundVariantDto $row): string => $row->variantId, $found->rows);
        $retail = $this->retailPrices->records($storeId, $ids);
        $bands = $this->bands->forVariants($storeId, $ids);
        $terms = $this->catalog->wholesaleOf($storeId, $ids);
        $rows = [];

        foreach ($found->rows as $row) {
            $id = $row->variantId;
            $own = $bands[$id] ?? [];
            $term = $terms[$id] ?? null;

            $rows[] = new WholesaleRow(
                $this->views->size($row),
                $this->views->retail($retail[$id] ?? null, $store),
                $this->views->bands($own, $store),
                $term?->minimum,
                $term?->maximum,
                ! ($term->soldWholesale ?? false),
                ! isset($retail[$id]),
                $own !== [] && $term?->minimum !== null && $own[0]->fromQuantity !== $term->minimum,
            );
        }

        return new WholesaleList($rows, $found->next, $found->total);
    }
}
