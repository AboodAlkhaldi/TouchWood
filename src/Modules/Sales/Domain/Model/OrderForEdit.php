<?php

declare(strict_types=1);

namespace Modules\Sales\Domain\Model;

/**
 * What an edit reads of a placed order (sales.md §1.7): its rules as placed — the VAT rate, the shipping
 * (its fee, rule, carrier, or the fee to be set) — its coupon and points, its gift, its address, and its lines, the parts of a
 * size oldest first (placement, then each edit in turn). Amounts in its currency's smallest unit.
 */
final readonly class OrderForEdit
{
    /**
     * @param  array<string, mixed>  $address
     * @param  list<OrderLine>  $lines
     */
    public function __construct(
        public string $id,
        public string $storeId,
        public string $customerId,
        public string $audience,
        public string $currencyCode,
        public int $vatRateBasisPoints,
        public OrderShipping $shipping,
        public ?string $couponId,
        public int $couponDiscount,
        public int $pointsUsed,
        public int $pointsDiscount,
        public ?string $giftLevelId,
        public int $orderTotal,
        public int $paid,
        public int $refunded,
        public array $address,
        public bool $wired,
        public array $lines,
    ) {}
}
