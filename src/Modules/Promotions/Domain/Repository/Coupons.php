<?php

declare(strict_types=1);

namespace Modules\Promotions\Domain\Repository;

use Modules\Promotions\Domain\Model\Coupon;
use Modules\Promotions\Domain\Model\CouponUsage;
use Shared\Domain\ValueObject\StoreId;

/**
 * A store's coupons (promotions.md §1.1, §1.8): every call names the store, so nothing of one store
 * reaches another's.
 */
interface Coupons
{
    /** The store's coupon carrying the code — already upper case, Latin digits — with its rules; null for none. */
    public function byCode(StoreId $store, string $code): ?Coupon;

    /** One customer's uses of the coupon, and whether it was assigned to them. */
    public function usage(StoreId $store, string $couponId, string $customerId): CouponUsage;
}
