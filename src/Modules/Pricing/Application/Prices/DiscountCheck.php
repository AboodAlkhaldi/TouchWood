<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Prices;

use Carbon\CarbonImmutable;
use Modules\Catalog\Public\Contracts\CatalogApi;
use Modules\Pricing\Domain\Exception\AlwaysWinsOverlap;
use Modules\Pricing\Domain\Exception\CategoryNotUsable;
use Modules\Pricing\Domain\Exception\OverCeiling;
use Modules\Pricing\Domain\Exception\PercentOutOfRange;
use Modules\Pricing\Domain\Exception\WindowInvalid;
use Modules\Pricing\Domain\Repository\CategoryDiscounts;
use Modules\Pricing\Domain\Repository\RetailPrices;
use Modules\Pricing\Domain\Repository\Sales;
use Modules\Pricing\Domain\ValueObject\CategoryDiscount;
use Shared\Domain\ValueObject\StoreId;

/**
 * What a category discount must be when it is saved (pricing.md §1.5, §1.12, §7), read under the store's
 * lock:
 * - its end after its start; a percentage from 0.01 to 99.99;
 * - its category there and switched on (Catalog's amendment 17; owner, 2026-10-13);
 * - no deeper than the store's ceiling (owner, 2026-10-11): a percentage above it; a fixed amount more
 *   than it below the retail price of any size it prices - refused whole, naming the sizes; a size the
 *   amount would take to 0 or below is skipped, not refused (§1.5);
 * - marked "always wins", no other "always wins" sale or discount on its sizes in the same dates
 *   (owner, 2026-10-09).
 */
final readonly class DiscountCheck
{
    public function __construct(
        private CatalogApi $catalog,
        private DiscountCoverage $coverage,
        private RetailPrices $retailPrices,
        private CeilingCheck $ceiling,
        private Sales $sales,
        private CategoryDiscounts $discounts,
    ) {}

    /**
     * @throws WindowInvalid|PercentOutOfRange|CategoryNotUsable|OverCeiling|AlwaysWinsOverlap
     */
    public function check(StoreId $store, CategoryDiscount $discount): void
    {
        if ($discount->endsAt !== null && $discount->endsAt <= $discount->startsAt) {
            throw new WindowInvalid;
        }

        if ($discount->percentBasisPoints !== null && ($discount->percentBasisPoints < 1 || $discount->percentBasisPoints > 9999)) {
            throw new PercentOutOfRange;
        }

        $category = $this->catalog->category($discount->categoryId);

        if ($category === null || ! $category->isActive) {
            throw new CategoryNotUsable;
        }

        $sizes = $this->coverage->pricedSizes($store, [$discount]);
        $ceiling = $this->ceiling->ceiling($store);

        if ($discount->percentBasisPoints !== null && $discount->percentBasisPoints > $ceiling * 100) {
            throw new OverCeiling($ceiling);
        }

        if ($discount->amount !== null) {
            $over = [];

            foreach ($this->retailPrices->forVariants($store, array_keys($sizes)) as $variantId => $retail) {
                if ($discount->priceOn($retail) !== null && $discount->depthOn($retail) > $ceiling * 100) {
                    $over[] = (string) $variantId;
                }
            }

            if ($over !== []) {
                sort($over);

                throw new OverCeiling($ceiling, [], $over);
            }
        }

        if ($discount->alwaysWins) {
            $this->refuseTwoThatAlwaysWin($store, $discount, $sizes);
        }
    }

    /**
     * A running discount given a new end (§4.1): only its dates are checked again.
     *
     * @throws WindowInvalid|AlwaysWinsOverlap
     */
    public function checkNewEnd(StoreId $store, CategoryDiscount $discount): void
    {
        if ($discount->endsAt !== null && $discount->endsAt <= $discount->startsAt) {
            throw new WindowInvalid;
        }

        if ($discount->alwaysWins) {
            $this->refuseTwoThatAlwaysWin($store, $discount, $this->coverage->pricedSizes($store, [$discount]));
        }
    }

    /**
     * @param  array<string, string>  $sizes  size id => its product
     */
    private function refuseTwoThatAlwaysWin(StoreId $store, CategoryDiscount $discount, array $sizes): void
    {
        $now = CarbonImmutable::now()->toDateTimeImmutable();
        $clash = [];

        foreach ($this->sales->notEndedOn($store, array_keys($sizes), $now) as $sale) {
            if ($sale->alwaysWins && $sale->overlaps($discount->startsAt, $discount->endsAt)) {
                array_push($clash, ...array_intersect($sale->variantIds, array_keys($sizes)));
            }
        }

        foreach ($this->discounts->notEnded($store, $now) as $other) {
            if ($other->id !== $discount->id && $other->alwaysWins && $other->overlaps($discount->startsAt, $discount->endsAt)) {
                $theirs = $this->coverage->products($other);
                array_push($clash, ...array_keys(array_filter($sizes, static fn (string $productId): bool => isset($theirs[$productId]))));
            }
        }

        if ($clash !== []) {
            $clash = array_values(array_unique(array_map('strval', $clash)));
            sort($clash);

            throw new AlwaysWinsOverlap($clash);
        }
    }
}
