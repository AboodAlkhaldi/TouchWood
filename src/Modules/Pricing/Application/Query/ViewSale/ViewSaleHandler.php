<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\ViewSale;

use Carbon\CarbonImmutable;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Catalog\Public\Enums\ProductStage;
use Modules\Pricing\Application\Prices\PriceViews;
use Modules\Pricing\Application\Prices\ReadAccess;
use Modules\Pricing\Application\Prices\SaleRows;
use Modules\Pricing\Domain\Exception\InvalidPricingInput;
use Modules\Pricing\Domain\Exception\PriceNotPositive;
use Modules\Pricing\Domain\Exception\SaleNotFound;
use Modules\Pricing\Domain\Exception\StoreOff;
use Modules\Pricing\Domain\Repository\Sales;
use Modules\Pricing\Domain\Service\PercentOff;
use Shared\Application\Unauthorized;
use Shared\Domain\ValueObject\Money;

/**
 * **One sale** (`ViewSale`, pricing.md §4.3.3 P2), for whoever holds any of Pricing's three jobs in its
 * store: every field, for its read-only dialog and Sync's link. A fixed few queries. A read: no lock,
 * nothing written.
 */
final readonly class ViewSaleHandler
{
    public function __construct(
        private ReadAccess $access,
        private Sales $sales,
        private SaleRows $rows,
        private CatalogApi $catalog,
        private PriceViews $views,
    ) {}

    /**
     * @throws SaleNotFound|Unauthorized|InvalidPricingInput|StoreOff
     */
    public function handle(ViewSale $query): SaleView
    {
        // Its store first, then the reader's permission there, then the sale: an id that exists in a store the
        // reader may not read is refused (403), one that does not exist is not found.
        $store = $this->access->authorize($this->sales->storeOf($query->saleId) ?? throw new SaleNotFound);
        $sale = $this->sales->find($query->saleId) ?? throw new SaleNotFound;
        [$rows, $variants, $retail] = $this->rows->rows([$sale], $store, CarbonImmutable::now()->toDateTimeImmutable());
        $row = $rows[0];
        $switchedOn = array_fill_keys($this->catalog->switchedOnVariantIds($store->storeId()), true);
        $productArchived = ($this->catalog->product($sale->productId)->stage ?? ProductStage::Archived) === ProductStage::Archived;
        $sizes = [];

        foreach ($sale->variantIds as $i => $variantId) {
            $variant = $variants[$variantId] ?? null;
            $price = $retail[$variantId] ?? null;
            $onSize = $price === null ? null : self::saleOn($sale->percentBasisPoints, $sale->price, $price);

            $sizes[] = new SaleSizeView(
                $variantId,
                $row->sizeNames[$i] ?? '',
                $variant->code ?? '',
                $productArchived || $variant === null || $variant->isArchived,
                isset($switchedOn[$variantId]),
                $price === null ? null : $this->views->money($price, $store),
                $onSize === null ? null : $this->views->money($onSize, $store),
            );
        }

        return new SaleView(strtolower($store->id), $row, $sizes);
    }

    private static function saleOn(?int $percent, ?Money $price, Money $retail): ?Money
    {
        if ($percent === null) {
            return $price;
        }

        try {
            return PercentOff::price($retail, $percent);
        } catch (PriceNotPositive) {
            return null;
        }
    }
}
