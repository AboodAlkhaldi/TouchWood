<?php

declare(strict_types=1);

namespace Modules\Loyalty\Public\Dto;

use Shared\Domain\ValueObject\Money;

/**
 * An order delivered (loyalty.md §1.6, §2.2): its points are earned on `goods_total` — after coupon and
 * points, before shipping and VAT (handoff §10.2) — and recorded even when they come to 0.
 */
final readonly class EarnPoints
{
    public function __construct(
        public string $orderId,
        public string $orderNumber,
        public string $customerId,
        public string $storeId,
        public Money $goodsTotal,
        public CallerPermission $caller,
    ) {}
}
