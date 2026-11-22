<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Prices;

use DateTimeImmutable;
use DateTimeInterface;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Dto\VariantDto;
use Modules\Catalog\Public\Enums\ProductStage;
use Modules\Platform\Public\Dto\StoreDto;
use Modules\Pricing\Application\Query\ListSales\SaleRow;
use Modules\Pricing\Domain\Repository\RetailPrices;
use Modules\Pricing\Domain\ValueObject\Sale;
use Modules\Pricing\Domain\ValueObject\WindowState;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;

/**
 * **Sales as the Offers screen shows them** (pricing.md §4.3.3 P2), a page at a time: named by their
 * products and sizes, "All Sizes" or "n of m" of the sizes switched on now, the sizes archived since, the
 * sizes with no price now. Read for the whole page at once - a fixed six queries however many rows (the
 * sizes' names one read a thousand sizes).
 */
final readonly class SaleRows
{
    /** Sizes a read of Catalog: under PostgreSQL's bind-parameter limit. */
    private const int CHUNK = 1000;

    public function __construct(
        private CatalogApi $catalog,
        private RetailPrices $retailPrices,
        private PriceViews $views,
    ) {}

    /**
     * @param  list<Sale>  $sales
     * @return array{list<SaleRow>, array<string, VariantDto>, array<string, Money>} the rows, the sales' sizes, their retail prices
     */
    public function rows(array $sales, StoreDto $store, DateTimeImmutable $now): array
    {
        if ($sales === []) {
            return [[], [], []];
        }

        $storeId = $store->storeId();
        $sizeIds = array_values(array_unique(array_merge(...array_map(static fn (Sale $sale): array => $sale->variantIds, $sales))));
        $productIds = array_values(array_unique(array_map(static fn (Sale $sale): string => $sale->productId, $sales)));
        $products = $this->catalog->products($productIds);
        $variants = [];

        foreach (array_chunk($sizeIds, self::CHUNK) as $chunk) {
            $variants += $this->catalog->variants($chunk);
        }

        $switchedOn = array_fill_keys($this->catalog->switchedOnVariantIds($storeId), true);
        $productSizes = $this->catalog->variantIdsOfProducts($productIds);
        $retail = $this->retailPrices($storeId, $sizeIds);
        $rows = [];

        foreach ($sales as $sale) {
            $product = $products[$sale->productId] ?? null;
            $productArchived = $product === null || $product->stage === ProductStage::Archived;
            $on = array_values(array_filter($productSizes[$sale->productId] ?? [], static fn (string $id): bool => isset($switchedOn[$id])));
            $covered = array_values(array_intersect($sale->variantIds, $on));
            $names = [];
            $unpriced = [];
            $archived = 0;

            foreach ($sale->variantIds as $variantId) {
                $variant = $variants[$variantId] ?? null;
                $name = $variant === null ? '' : $this->views->sizeName($variant->values);
                $names[] = $name;

                if ($productArchived || $variant === null || $variant->isArchived) {
                    $archived++;
                }

                if (! isset($retail[$variantId])) {
                    $unpriced[] = $name;
                }
            }

            $rows[] = new SaleRow(
                $sale->id,
                $sale->productId,
                $product->nameAr ?? '',
                $product?->nameEn,
                WindowState::of($sale->startsAt, $sale->endsAt, $now)->value,
                $sale->percentBasisPoints,
                $sale->price === null ? null : $this->views->money($sale->price, $store),
                $sale->startsAt->format(DateTimeInterface::ATOM),
                $sale->endsAt?->format(DateTimeInterface::ATOM),
                $sale->createdAt?->format(DateTimeInterface::ATOM),
                $sale->alwaysWins,
                $sale->variantIds,
                $names,
                $on !== [] && count($covered) === count($on),
                count($covered),
                count($on),
                $archived,
                $unpriced,
            );
        }

        return [$rows, $variants, $retail];
    }

    /**
     * @param  list<string>  $sizeIds
     * @return array<string, Money>
     */
    private function retailPrices(StoreId $store, array $sizeIds): array
    {
        $retail = [];

        foreach (array_chunk($sizeIds, self::CHUNK) as $chunk) {
            $retail += $this->retailPrices->forVariants($store, $chunk);
        }

        return $retail;
    }
}
