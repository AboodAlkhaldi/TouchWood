<?php

declare(strict_types=1);

namespace Modules\Promotions\Public\Dto;

use Modules\Pricing\Public\Dto\CartPricesDto;
use Modules\Promotions\Public\Enums\Audience;

/**
 * A coupon tried on a cart (promotions.md §2.2): the store, the code as the customer typed it — any
 * case, Arabic digits read as 0-9 — the customer, who is buying, and Pricing's prices for the cart.
 */
final readonly class CouponRequest
{
    public function __construct(
        public string $storeId,
        public string $code,
        public string $customerId,
        public Audience $audience,
        public CartPricesDto $prices,
    ) {}
}
