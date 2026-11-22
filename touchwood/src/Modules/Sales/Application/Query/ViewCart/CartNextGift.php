<?php

declare(strict_types=1);

namespace Modules\Sales\Application\Query\ViewCart;

use Shared\Domain\ValueObject\Money;

/**
 * The next gift level the cart does not reach yet (sales.md §4.2 B12): its amount, and how much more the
 * goods need — both without VAT, like the goods total checkout compares with the level (§1.2 step 8).
 */
final readonly class CartNextGift
{
    public function __construct(
        public Money $amount,
        public Money $missing,
    ) {}
}
