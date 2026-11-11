<?php

declare(strict_types=1);

namespace Modules\Catalog\Public\Dto;

use InvalidArgumentException;
use Shared\Domain\ValueObject\Money;

/**
 * **A variant's price in a store, as Pricing pushes it** (catalog.md §2.2, amendment 15, 16(i)):
 * `$now`, what one piece costs now there — the price as Pricing resolves it, never a quantity price —
 * and `$before`, the base price **only while `$now` is lower** (the old price crossed out beside the
 * new one). Both without VAT, in the store's currency. **`$wholesaleFrom`** (amendment 35): the least one
 * piece costs now on a wholesale line, at any quantity — its deepest band, or a sale or discount below it
 * — the card's "Wholesale from" where the size sells wholesale. **`$wholesaleFromBefore`** (amendment 43): the
 * least it would cost there with no sale, discount or campaign — the line's normal price, its band or the
 * retail price — **only while `$wholesaleFrom` is lower**, crossed out beside it. A band alone is never a
 * reduction, so it never gives one.
 */
final readonly class ListingPrice
{
    public function __construct(
        public Money $now,
        public ?Money $before = null,
        public ?Money $wholesaleFrom = null,
        public ?Money $wholesaleFromBefore = null,
    ) {
        if ($before !== null && ($before->currencyCode !== $now->currencyCode || $before->minorUnits <= $now->minorUnits)) {
            throw new InvalidArgumentException('A price before is only kept while the price now is lower, in the same currency.');
        }

        if ($wholesaleFrom !== null && $wholesaleFrom->currencyCode !== $now->currencyCode) {
            throw new InvalidArgumentException('A wholesale price is in the same currency as the price now.');
        }

        if ($wholesaleFromBefore !== null && ($wholesaleFrom === null || $wholesaleFromBefore->currencyCode !== $wholesaleFrom->currencyCode || $wholesaleFromBefore->minorUnits <= $wholesaleFrom->minorUnits)) {
            throw new InvalidArgumentException('A wholesale price before is only kept while the wholesale price is lower, in the same currency.');
        }
    }
}
