<?php

declare(strict_types=1);

namespace Modules\Promotions\Public\Contracts;

use Modules\Promotions\Public\Dto\CouponRequest;
use Modules\Promotions\Public\Dto\CouponResult;
use Shared\Domain\ValueObject\Money;
use Shared\Domain\ValueObject\StoreId;

/**
 * What Sales asks Promotions (promotions.md §2.1): ids and values in, DTOs out (handoff §4.3). The
 * reads have no permission of their own — they serve flows their callers have authorized. The writes
 * Sales makes inside its checkout and its edits come with step 2b.
 */
interface PromotionsApi
{
    /**
     * Sales's cart and quote (§1.3): what the coupon takes off, line by line, or why it is refused —
     * whole, never trimmed. Whether the order also uses points is decided after it: a coupon that does
     * not allow them means the order uses none (`allowWithPoints`). Reads only.
     */
    public function applyCoupon(CouponRequest $request): CouponResult;

    /**
     * The room left under the store's discount ceiling for a coupon, then points (§1.6): what the
     * ceiling allows off `gross_subtotal`, less what the prices already took, never below 0.
     */
    public function customerDiscountRoom(StoreId $store, Money $grossSubtotal, Money $netSubtotal): Money;
}
