<?php

declare(strict_types=1);

namespace Modules\Pricing\Domain\ValueObject;

use DateTimeImmutable;
use Modules\Pricing\Domain\Exception\PriceNotPositive;
use Modules\Pricing\Domain\Service\PercentOff;
use Shared\Domain\ValueObject\Money;

/**
 * A category discount (pricing.md §1.5): a percentage, or a fixed amount, off the retail price of every
 * product in a category and below it, in one store, on retail lines, wholesale lines or both; products
 * left out by name; from its start, until its end or for good. It covers what is in the category now,
 * never a list kept when it was made (owner, 2026-10-08). Its life is read from the dates (§4.1).
 */
final readonly class CategoryDiscount
{
    use DatedWindow;

    /**
     * @param  list<string>  $excludedProductIds
     */
    public function __construct(
        public string $id,
        public string $storeId,
        public string $categoryId,
        public ?int $percentBasisPoints,
        public ?Money $amount,
        public DiscountLines $lines,
        public DateTimeImmutable $startsAt,
        public ?DateTimeImmutable $endsAt,
        public bool $alwaysWins,
        public array $excludedProductIds,
    ) {}

    /**
     * Its price on a size: a percentage of the retail price, half up to the coin; the retail price less
     * the amount. Null where it would leave 0 or less - a fixed amount skips that size (§1.5).
     */
    public function priceOn(Money $retail): ?Money
    {
        if ($this->percentBasisPoints !== null) {
            try {
                return PercentOff::price($retail, $this->percentBasisPoints);
            } catch (PriceNotPositive) {
                return null;
            }
        }

        $left = $retail->minorUnits - ($this->amount->minorUnits ?? 0);

        return $left > 0 ? Money::of($left, $retail->currencyCode) : null;
    }

    /**
     * How deep it goes on a size, in basis points of the size's retail price: a percentage as it is; a
     * fixed amount by its share of the retail price, rounded up.
     */
    public function depthOn(Money $retail): int
    {
        if ($this->percentBasisPoints !== null) {
            return $this->percentBasisPoints;
        }

        return $retail->minorUnits <= 0 ? 0 : (int) ceil(($this->amount->minorUnits ?? 0) * 10000 / $retail->minorUnits);
    }
}
