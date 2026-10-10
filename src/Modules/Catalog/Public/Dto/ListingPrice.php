<?php

declare(strict_types=1);

namespace Modules\Catalog\Public\Dto;

use InvalidArgumentException;
use Shared\Domain\ValueObject\Money;

/**
 * **A variant's price in a store, as Pricing pushes it** (catalog.md §2.2, amendment 15, 16(i)):
 * `$now`, what one piece costs now there — the price as Pricing resolves it, never a quantity price —
 * and `$before`, the base price **only while `$now` is lower** (the old price crossed out beside the
 * new one). Both without VAT, in the store's currency.
 */
final readonly class ListingPrice
{
    public function __construct(
        public Money $now,
        public ?Money $before = null,
    ) {
        if ($before !== null && ($before->currencyCode !== $now->currencyCode || $before->minorUnits <= $now->minorUnits)) {
            throw new InvalidArgumentException('A price before is only kept while the price now is lower, in the same currency.');
        }
    }
}
