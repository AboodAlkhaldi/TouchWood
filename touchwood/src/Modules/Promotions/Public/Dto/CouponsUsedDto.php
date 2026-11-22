<?php

declare(strict_types=1);

namespace Modules\Promotions\Public\Dto;

use Shared\Domain\ValueObject\Money;

/** A store's coupon uses over a range, for Ops' daily report (`PromotionsApi::couponsUsed`). */
final readonly class CouponsUsedDto
{
    public function __construct(
        /** Orders placed with a coupon in the range — a cancelled one still counts (owner: a use never comes back). */
        public int $uses,
        /** Their discounts together, in the store's currency, as edits left them. */
        public Money $discount,
    ) {}
}
