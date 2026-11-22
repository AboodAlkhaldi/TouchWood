<?php

declare(strict_types=1);

namespace Modules\Loyalty\Domain\Model\Settlement;

/**
 * An order's points facts and running totals (loyalty.md §5, `loyalty.orders`), as a settlement reads
 * and moves them. The totals only grow; each is bounded by the table's CHECKs.
 */
final class OrderPointsFacts
{
    public function __construct(
        public readonly int $pointsRedeemed,
        public readonly int $discountMinor,
        public readonly int $pointsEarned,
        /** null before delivery. */
        public readonly ?int $goodsTotalMinor,
        public int $returnedGoodsMinor = 0,
        public int $returnedDiscountMinor = 0,
        public int $pointsGivenBack = 0,
        public int $pointsBackThroughCovers = 0,
        public int $pointsReversed = 0,
        public int $pointsCountedGone = 0,
        public int $pointsCovered = 0,
        public int $pointsDropped = 0,
        public int $expiredBackUnused = 0,
        /** The `max_redemption_percent` it redeemed under (§1.8): an edit keeps the order's own rules. */
        public readonly ?int $capPercent = null,
    ) {}

    /** The earned points settled so far: taken, counted as gone, covered or dropped. */
    public function takenBack(): int
    {
        return $this->pointsReversed + $this->pointsCountedGone + $this->pointsCovered + $this->pointsDropped;
    }
}
