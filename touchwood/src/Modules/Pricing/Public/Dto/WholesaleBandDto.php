<?php

declare(strict_types=1);

namespace Modules\Pricing\Public\Dto;

use Shared\Domain\ValueObject\Money;

/** One wholesale band of a size in a store (pricing.md §1.4): from a quantity, one piece at this price, without VAT. */
final readonly class WholesaleBandDto
{
    public function __construct(
        public int $fromQuantity,
        public Money $unit,
    ) {}
}
