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
}
