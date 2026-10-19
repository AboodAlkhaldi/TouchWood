<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\ViewSizePrices;

use Carbon\CarbonImmutable;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Enums\SaleMode;
use Modules\Pricing\Application\Prices\PriceViews;
use Modules\Pricing\Application\Prices\ReachViews;
use Modules\Pricing\Application\Prices\ReadAccess;
use Modules\Pricing\Domain\Exception\InvalidPricingInput;
use Modules\Pricing\Domain\Exception\StoreOff;
use Modules\Pricing\Domain\Repository\CategoryDiscounts;
use Modules\Pricing\Domain\Repository\PriceCandidates;
use Modules\Pricing\Domain\Repository\RetailPrices;
use Modules\Pricing\Domain\Repository\Sales;
use Modules\Pricing\Domain\Repository\WholesaleBands;
use Modules\Pricing\Domain\ValueObject\CategoryDiscount;
use Shared\Application\Unauthorized;

/**
 * **A size's drawer** (`ViewSizePrices`, pricing.md §4.3.3, Q3), for whoever holds any of Pricing's three
 * jobs in the store - any size, archived ones too: read-only is not hidden (owner, 2026-10-11). Its prices
 * are read from what each change writes (the candidates, §1.6), so the drawer says exactly what an order
 * would pay; its sales and discounts from their own tables, each named. A fixed few queries - at most
 * thirteen. A read: no lock, nothing written.
 */
final readonly class ViewSizePricesHandler
{
    public function __construct(
        private ReadAccess $access,
        private CatalogApi $catalog,
        private RetailPrices $retailPrices,
        private PriceCandidates $candidates,
        private WholesaleBands $bands,
        private Sales $sales,
        private CategoryDiscounts $discounts,
        private PriceViews $views,
        private ReachViews $reach,
    ) {}

    /**
     * @throws Unauthorized|InvalidPricingInput|StoreOff
     */
    public function handle(ViewSizePrices $query): SizePricesView
    {
        $store = $this->access->authorize($query->storeId);
        $storeId = $store->storeId();
        $id = strtolower(trim($query->variantId));
        $found = $this->catalog->searchVariants($storeId, null, only: [$id], limit: 1)->rows[0] ?? throw new InvalidPricingInput('variant');
        $now = CarbonImmutable::now()->toDateTimeImmutable();
        $retail = $this->retailPrices->records($storeId, [$id])[$id] ?? null;
        $candidates = $this->candidates->forVariants($storeId, [$id])[$id] ?? [];
        $bands = $this->bands->forVariants($storeId, [$id])[$id] ?? [];
        $terms = $this->catalog->wholesaleOf($storeId, [$id])[$id] ?? null;
        $discounts = $this->discounts->notEnded($storeId, $now);
        $categoryIds = $discounts === [] ? [] : ($this->catalog->lineFacts([$id])[$id]->categoryIds ?? []);
        $reaching = ReachViews::reaching($discounts, $found->productId, $categoryIds);
        $paths = $reaching === [] ? [] : $this->catalog->categoriesWithPaths(array_values(array_unique(array_map(static fn (CategoryDiscount $discount): string => $discount->categoryId, $reaching))));
        $soldWholesale = $terms->soldWholesale ?? false;
        $quantity = $soldWholesale ? max(1, $terms->minimum ?? 1) : null;

        return new SizePricesView(
            $this->views->size($found),
            $this->views->retail($retail, $store),
            $this->views->today($candidates, SaleMode::Retail, 1, $now, $store),
            $quantity === null ? null : $this->views->today($candidates, SaleMode::Wholesale, $quantity, $now, $store),
            $quantity,
            $terms->soldRetail ?? false,
            $soldWholesale,
            $this->reach->sales($this->sales->notEndedOn($storeId, [$id], $now), $id, $retail?->amount, $candidates, $now, $store),
            $this->reach->discounts($reaching, $found->productId, $categoryIds, $retail?->amount, $candidates, $paths, $now, $store),
            $this->views->bands($bands, $store),
            $terms?->minimum,
            $terms?->maximum,
            $bands !== [] && $terms?->minimum !== null && $bands[0]->fromQuantity !== $terms->minimum,
        );
    }
}
