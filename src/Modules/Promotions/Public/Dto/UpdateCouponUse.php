<?php

declare(strict_types=1);

namespace Modules\Promotions\Public\Dto;

use Shared\Domain\ValueObject\Money;

/**
 * A coupon use's new discount after a staff edit (promotions.md §1.3, §2.2) — never higher; recorded
 * once per edit — and the caller's permission.
 */
final readonly class UpdateCouponUse
{
    public function __construct(
        public string $orderId,
        public string $editId,
        public Money $newDiscount,
        public CallerPermission $caller,
    ) {}
}
