<?php

declare(strict_types=1);

namespace Modules\Pricing\Application\Prices;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Modules\Pricing\Domain\Exception\AlwaysWinsOverlap;
use Modules\Pricing\Domain\Exception\NoRetailPrice;
use Modules\Pricing\Domain\Exception\OverCeiling;
use Modules\Pricing\Domain\Exception\PercentOutOfRange;
use Modules\Pricing\Domain\Exception\PriceNotPositive;
use Modules\Pricing\Domain\Exception\SaleNotBelowRetail;
use Modules\Pricing\Domain\Exception\WindowInvalid;
use Modules\Pricing\Domain\Repository\RetailPrices;
use Modules\Pricing\Domain\Repository\Sales;
use Modules\Pricing\Domain\Service\PercentOff;
use Modules\Pricing\Domain\ValueObject\Sale;
use Shared\Domain\ValueObject\StoreId;

/**
 * What a sale must be when it is saved (pricing.md §1.3, §1.12), read under the store's lock:
 * - its end after its start;
 * - every size it covers with a retail price, a sale price below it, a percentage that leaves more
 *   than 0 (owner, 2026-10-10), and no deeper than the store's ceiling (owner, 2026-10-11) - one size
 *   that cannot take it refuses the whole sale, naming the sizes (owner, 2026-10-11);
 * - marked "always wins", no other "always wins" sale on those sizes in the same dates (owner,
 *   2026-10-09). Category discounts join this check with step 6.
 */
final readonly class SaleCheck
{
    public function __construct(
        private RetailPrices $retailPrices,
        private Sales $sales,
        private CeilingCheck $ceiling,
    ) {}

    /**
     * @throws WindowInvalid|PercentOutOfRange|NoRetailPrice|SaleNotBelowRetail|PriceNotPositive|OverCeiling|AlwaysWinsOverlap
     */
    public function check(StoreId $store, Sale $sale): void
    {
        if ($sale->endsAt !== null && $sale->endsAt <= $sale->startsAt) {
            throw new WindowInvalid;
        }

        if ($sale->percentBasisPoints !== null && ($sale->percentBasisPoints < 1 || $sale->percentBasisPoints > 9999)) {
            throw new PercentOutOfRange;
        }

        $retail = $this->retailPrices->forVariants($store, $sale->variantIds);
        $missing = array_values(array_diff($sale->variantIds, array_keys($retail)));

        if ($missing !== []) {
            throw new NoRetailPrice($missing);
        }

        $ceiling = $this->ceiling->ceiling($store);
        $notBelow = $over = [];

        foreach ($sale->variantIds as $variantId) {
            $price = $retail[$variantId];

            if ($sale->price !== null && $sale->price->compareTo($price) >= 0) {
                $notBelow[] = $variantId;

                continue;
            }

            if ($sale->percentBasisPoints !== null) {
                // A percentage that would leave 0 is refused (owner, 2026-10-10).
                PercentOff::price($price, $sale->percentBasisPoints);
            }

            if ($sale->depthOn($price) > $ceiling * 100) {
                $over[] = $variantId;
            }
        }

        if ($notBelow !== []) {
            throw new SaleNotBelowRetail($notBelow);
        }

        if ($over !== []) {
            throw new OverCeiling($ceiling, [], $over);
        }

        if ($sale->alwaysWins) {
            $this->refuseTwoThatAlwaysWin($store, $sale);
        }
    }

    private function refuseTwoThatAlwaysWin(StoreId $store, Sale $sale): void
    {
        $clash = [];

        foreach ($this->sales->notEndedOn($store, $sale->variantIds, self::now()) as $other) {
            if ($other->id !== $sale->id && $other->alwaysWins && $other->overlaps($sale->startsAt, $sale->endsAt)) {
                array_push($clash, ...array_intersect($other->variantIds, $sale->variantIds));
            }
        }

        if ($clash !== []) {
            $clash = array_values(array_unique($clash));
            sort($clash);

            throw new AlwaysWinsOverlap($clash);
        }
    }

    private static function now(): DateTimeImmutable
    {
        return CarbonImmutable::now()->toDateTimeImmutable();
    }
}
