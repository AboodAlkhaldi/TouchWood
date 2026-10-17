<?php

declare(strict_types=1);

namespace Modules\Pricing\Domain\ValueObject;

use Shared\Domain\ValueObject\Money;

/**
 * One wholesale band (pricing.md §1.4): from a quantity, at a price - e.g. "from 20 = 85".
 */
final readonly class Band
{
    public function __construct(
        public int $fromQuantity,
        public Money $amount,
    ) {}
}
