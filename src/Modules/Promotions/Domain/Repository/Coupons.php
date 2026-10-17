<?php

declare(strict_types=1);

namespace Modules\Promotions\Domain\Repository;

use DateTimeImmutable;
use Modules\Promotions\Domain\Model\Coupon;
use Modules\Promotions\Domain\Model\CouponUsage;
use Modules\Promotions\Domain\Model\CouponUse;
use Shared\Domain\ValueObject\StoreId;

/**
 * A store's coupons and their uses (promotions.md §1.1, §1.8): every call that reads a coupon names
 * the store, so nothing of one store reaches another's. A use is found by its order, whose coupon
 * names the store.
 */
interface Coupons
{
    /** The store's coupon carrying the code — already upper case, Latin digits — with its rules; null for none. */
    public function byCode(StoreId $store, string $code): ?Coupon;

    /** The store's coupon with its rules; null for none in that store. */
    public function byId(StoreId $store, string $couponId): ?Coupon;

    /**
     * The store's coupon with its rules, its row locked until the transaction ends — so two orders
     * never take its last use (§2.1). Null for none in that store.
     */
    public function lock(StoreId $store, string $couponId): ?Coupon;

    /** The store a coupon belongs to; null for no such coupon. */
    public function storeOf(string $couponId): ?StoreId;

    /** One customer's uses of the coupon, and whether it was assigned to them. */
    public function usage(StoreId $store, string $couponId, string $customerId): CouponUsage;

    /** The order's coupon use; null for none. With `$lock`, its row is locked until the transaction ends. */
    public function useOf(string $orderId, bool $lock = false): ?CouponUse;

    /** Records the order's use of the coupon and counts it on the coupon, whose row the caller has locked. */
    public function recordUse(Coupon $coupon, string $customerId, string $orderId, string $orderNumber, int $discountMinor, DateTimeImmutable $at): void;

    /** Lowers a use's discount for a staff edit, naming the edit (§1.3). */
    public function lowerUse(string $orderId, string $editId, int $discountMinor): void;
}
