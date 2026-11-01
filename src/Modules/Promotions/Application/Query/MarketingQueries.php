<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Query;

use DateTimeImmutable;
use Modules\Promotions\Public\Enums\CouponState;
use Shared\Domain\ValueObject\StoreId;

/**
 * The staff screens' reads of a store's marketing (promotions.md §3), in SQL: every read names its store
 * or its item, a page at a time by keyset (handoff §5.4), a fixed number of queries per page. Customers
 * come back as ids; the handlers name them through Access in one call.
 */
interface MarketingQueries
{
    /**
     * The store's coupons, newest first, after the cursor; only those in `$state` now when one is given,
     * only codes starting with `$codeStart` (as `Inputs::codeStart` reads it) when one is given.
     *
     * @return list<CouponRow>
     */
    public function coupons(StoreId $store, ?CouponState $state, ?string $codeStart, ?DateTimeImmutable $beforeAt, ?string $beforeId, int $limit, DateTimeImmutable $now): array;

    /** One coupon's row, its state now. */
    public function couponRow(StoreId $store, string $couponId, DateTimeImmutable $now): ?CouponRow;

    public function assignedCount(string $couponId): int;

    /**
     * A coupon's uses, newest first, after the cursor.
     *
     * @return list<array{id: int, orderNumber: string, customerId: string, discountMinor: int, usedAt: DateTimeImmutable}>
     */
    public function uses(string $couponId, ?DateTimeImmutable $beforeAt, ?int $beforeId, int $limit): array;

    /**
     * A coupon's assignments, newest first, after the cursor, each with its segment's names and the
     * customer's uses of the coupon.
     *
     * @return list<array{customerId: string, assignedAt: DateTimeImmutable, segmentId: ?string, segmentNameAr: ?string, segmentNameEn: ?string, uses: int}>
     */
    public function assignments(string $couponId, ?DateTimeImmutable $beforeAt, ?string $beforeCustomerId, int $limit): array;

    /**
     * The store's segments, newest first, after the cursor, each with how many members it holds.
     *
     * @return list<SegmentRow>
     */
    public function segments(StoreId $store, ?DateTimeImmutable $beforeAt, ?string $beforeId, int $limit): array;

    /**
     * The store's segments, newest first by id, after the cursor, each with how many members it holds.
     *
     * @return list<SegmentRow>
     */
    public function segmentsById(StoreId $store, ?string $beforeId, int $limit): array;

    /**
     * A segment's members by customer id, after the cursor.
     *
     * @return list<array{customerId: string, addedAt: DateTimeImmutable}>
     */
    public function members(string $segmentId, ?string $afterCustomerId, int $limit): array;

    /** One segment of the store with how many it holds; null when the store holds no such segment. */
    public function segmentRow(StoreId $store, string $segmentId): ?SegmentRow;

    /**
     * The coupons of the store given to this customer, with the customer's uses of each — whether they
     * can still use one is the caller's to work out.
     *
     * @return list<array{row: CouponRow, cap: ?int, minimum: ?int, maxUsesPerCustomer: ?int, usesByMe: int}>
     */
    public function assignedTo(StoreId $store, string $customerId, DateTimeImmutable $now): array;

    /**
     * The store's coupon uses from `from` up to `to` (not included): how many, and their discounts
     * together in the smallest unit (§2.1, Ops' daily report). One query.
     *
     * @return array{int, int}
     */
    public function usesIn(StoreId $store, DateTimeImmutable $from, DateTimeImmutable $to): array;

    /**
     * The customers one assignment added, by id, after `after` (§2.1, Ops' messages).
     *
     * @return list<string>
     */
    public function assignedBy(string $assignmentId, ?string $after, int $limit): array;
}
