<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Query\OverlapsFor;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Modules\Pricing\Application\Prices\DiscountCoverage;
use Modules\Pricing\Application\Prices\ReadAccess;
use Modules\Pricing\Application\Prices\SaleInput;
use Modules\Pricing\Application\Prices\SaleSizes;
use Modules\Pricing\Domain\Exception\InvalidPricingInput;
use Modules\Pricing\Domain\Repository\CategoryDiscounts;
use Modules\Pricing\Domain\Repository\Sales;
use Shared\Application\Unauthorized;

/**
 * **The note before saving** (`OverlapsFor`, pricing.md §1.3, §3), for whoever holds any of Pricing's
 * three jobs in that store (owner, 2026-10-08). The sizes are read as adding the sale reads them
 * (`SaleSizes`), its start now when empty or past; every sale not ended on those sizes whose dates meet
 * it is named, the sale itself left out when it is being changed - and every category discount reaching
 * the product in those dates (step 7b). A read: no lock, nothing written.
 */
final readonly class OverlapsForHandler
{
    public function __construct(
        private ReadAccess $access,
        private SaleSizes $sizes,
        private Sales $sales,
        private CategoryDiscounts $discounts,
        private DiscountCoverage $coverage,
    ) {}

    /**
     * @throws Unauthorized|InvalidPricingInput
     */
    public function handle(OverlapsFor $query): OverlapNote
    {
        $store = $this->access->authorize($query->storeId)->storeId();
        [$productId, $variantIds] = $this->sizes->of($query->productId, $query->variantIds);
        $now = CarbonImmutable::now()->toDateTimeImmutable();
        $startsAt = SaleInput::start($query->startsAt, $now);
        $endsAt = SaleInput::end($query->endsAt);
        $overlapping = [];
        // Ids are kept in lower case, as the sale's own handlers read them.
        $itself = $query->saleId === null ? null : strtolower(trim($query->saleId));

        foreach ($this->sales->notEndedOn($store, $variantIds, $now) as $sale) {
            if ($sale->id !== $itself && $sale->overlaps($startsAt, $endsAt)) {
                $overlapping[] = new OverlappingSale(
                    $sale->id,
                    $sale->percentBasisPoints,
                    $sale->price?->minorUnits,
                    $sale->price?->currencyCode,
                    $sale->startsAt->format(DateTimeInterface::ATOM),
                    $sale->endsAt?->format(DateTimeInterface::ATOM),
                    $sale->alwaysWins,
                    array_values(array_intersect($sale->variantIds, $variantIds)),
                );
            }
        }

        $discounts = [];

        foreach ($this->discounts->notEnded($store, $now) as $discount) {
            if ($discount->overlaps($startsAt, $endsAt) && isset($this->coverage->products($discount)[$productId])) {
                $discounts[] = new OverlappingDiscount(
                    $discount->id,
                    $discount->categoryId,
                    $discount->percentBasisPoints,
                    $discount->amount?->minorUnits,
                    $discount->amount?->currencyCode,
                    $discount->lines->value,
                    $discount->startsAt->format(DateTimeInterface::ATOM),
                    $discount->endsAt?->format(DateTimeInterface::ATOM),
                    $discount->alwaysWins,
                );
            }
        }

        return new OverlapNote($productId, $variantIds, $overlapping, $discounts);
    }
}
