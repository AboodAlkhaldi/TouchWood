<?php

declare(strict_types=1);

namespace Modules\Pricing\Domain\ValueObject;

use DateTimeImmutable;
use Shared\Domain\ValueObject\Money;

/**
 * A size's retail price in a store as it is kept (pricing.md §5 `pricing.retail_prices`): the amount,
 * where it came from and when it last changed - what the screens show under a price (§4.3.3 P1: **Set
 * Here**, **From File**, **From Provider**, and its time; owner Q23).
 */
final readonly class RetailPrice
{
    public function __construct(
        public string $variantId,
        public Money $amount,
        public PriceSource $source,
        public DateTimeImmutable $updatedAt,
    ) {}
}
