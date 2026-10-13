<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\ListSales;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeInterface;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Pricing\Application\Prices\ReadAccess;
use Modules\Pricing\Domain\Exception\InvalidPricingInput;
use Modules\Pricing\Domain\Repository\Sales;
use Modules\Pricing\Domain\ValueObject\Sale;
use Shared\Application\Unauthorized;

/**
 * **The store's sales** (`ListSales`, pricing.md §3), for whoever holds any of Pricing's three jobs there
 * (owner, 2026-10-08): a page at a time, the latest start first, each named by its product in one read of
 * Catalog. A read: no lock, nothing written.
 */
final readonly class ListSalesHandler
{
    public function __construct(
        private ReadAccess $access,
        private Sales $sales,
        private CatalogApi $catalog,
    ) {}

    /**
     * @throws Unauthorized|InvalidPricingInput
     */
    public function handle(ListSales $query): SaleList
    {
        $store = $this->access->authorize($query->storeId)->storeId();
        [$sales, $total] = $this->sales->page($store, $query->page, ListSales::PER_PAGE);
        $products = $this->catalog->products(array_values(array_unique(array_map(static fn (Sale $sale): string => $sale->productId, $sales))));
        $now = CarbonImmutable::now()->toDateTimeImmutable();

        return new SaleList(array_map(static fn (Sale $sale): SaleRow => new SaleRow(
            $sale->id,
            $sale->productId,
            $products[$sale->productId]->nameAr ?? '',
            $products[$sale->productId]->nameEn ?? null,
            $sale->percentBasisPoints,
            $sale->price?->minorUnits,
            $sale->price?->currencyCode,
            $sale->startsAt->format(DateTimeInterface::ATOM),
            $sale->endsAt?->format(DateTimeInterface::ATOM),
            $sale->alwaysWins,
            self::state($sale, $now),
            $sale->variantIds,
        ), $sales), $total);
    }

    private static function state(Sale $sale, DateTimeImmutable $now): string
    {
        return $sale->scheduled($now) ? 'SCHEDULED' : ($sale->ended($now) ? 'ENDED' : 'RUNNING');
    }
}
