<?php

declare(strict_types=1);

namespace Modules\Promotions\Domain\Repository;

use DateTimeImmutable;
use Modules\Promotions\Domain\Model\Coupon;
use Modules\Promotions\Public\Enums\SegmentAudience;

/**
 * Who an `ASSIGNED` coupon was given to (promotions.md §1.4): written from a segment's members of that
 * moment, kept when they leave the segment later.
 */
interface Assignments
{
    /**
     * Gives the coupon to these customers; those who have it already keep their row. Answers how many
     * are new.
     *
     * @param  list<string>  $customerIds
     */
    public function assign(Coupon $coupon, array $customerIds, ?string $segmentId, DateTimeImmutable $at): int;

    /** Takes the coupon back from one customer; answers whether they had it. */
    public function remove(Coupon $coupon, string $customerId): bool;

    /**
     * The audiences of the segments the coupon was assigned from — a coupon may not then exclude one
     * (§1.1).
     *
     * @return list<SegmentAudience>
     */
    public function segmentAudiences(Coupon $coupon): array;
}
