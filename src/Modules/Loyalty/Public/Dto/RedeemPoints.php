<?php

declare(strict_types=1);

namespace Modules\Loyalty\Public\Dto;

use Modules\Loyalty\Public\Enums\Audience;
use Shared\Domain\ValueObject\Money;

/**
 * Placing an order with points (loyalty.md §1.7, §2.2), inside Sales's own transaction. Loyalty works
 * the redemption out again and refuses it unless it still gives the quote's points and discount.
 */
final readonly class RedeemPoints
{
    public function __construct(
        public string $orderId,
        /** Its public number, e.g. "SA-10428" — never the id. */
        public string $orderNumber,
        public string $customerId,
        public string $storeId,
        /** The quote's points charged. */
        public int $points,
        /** The quote's discount. */
        public Money $discount,
        public Audience $audience,
        public Money $netSubtotal,
        public CallerPermission $caller,
    ) {}
}
