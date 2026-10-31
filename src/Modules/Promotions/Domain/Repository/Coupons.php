<?php

declare(strict_types=1);

namespace Modules\Promotions\Domain\Repository;

use DateTimeImmutable;
use Modules\Promotions\Domain\Model\Coupon;
use Modules\Promotions\Domain\Model\CouponReservation;
use Modules\Promotions\Domain\Model\CouponTerms;
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

    /** The coupon held for this checkout try's order, or null. With `$lock`, its row is locked. */
    public function reservationOf(string $orderId, bool $lock = false): ?CouponReservation;

    /** Holds a use of the coupon, whose row the caller has locked, for an online payment (§9.5). */
    public function reserve(Coupon $coupon, string $customerId, string $orderId, int $discountMinor, DateTimeImmutable $at): void;

    /** The try's hold goes, counting nothing; false when there was none. */
    public function dropReservation(string $orderId): bool;

    /** Records the order's use of the coupon and counts it on the coupon, whose row the caller has locked. */
    public function recordUse(Coupon $coupon, string $customerId, string $orderId, string $orderNumber, int $discountMinor, DateTimeImmutable $at): void;

    /** Lowers a use's discount for a staff edit, naming the edit (§1.3). */
    public function lowerUse(string $orderId, string $editId, int $discountMinor): void;

    /** Whether another coupon of the store carries the code (§1.1), whatever its case was typed. */
    public function codeTaken(StoreId $store, string $code): bool;

    /** A new coupon with its rules, unused. */
    public function create(string $couponId, StoreId $store, string $code, CouponTerms $terms, bool $active, DateTimeImmutable $at): void;

    /** The coupon's terms and rules replaced; its code, store, uses and switch kept. */
    public function change(Coupon $coupon, CouponTerms $terms, DateTimeImmutable $at): void;

    public function switchTo(Coupon $coupon, bool $active, DateTimeImmutable $at): void;

    /** Whether any order used the coupon — then it is never deleted (§1.1). */
    public function wasUsed(Coupon $coupon): bool;

    /** An unused coupon gone, its rules and assignments with it (§1.1). */
    public function delete(Coupon $coupon): void;
}
