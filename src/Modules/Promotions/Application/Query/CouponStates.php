<?php

declare(strict_types=1);

namespace Modules\Promotions\Application\Query;

use DateTimeImmutable;
use Modules\Promotions\Public\Enums\CouponState;

/**
 * A coupon's state now (promotions.md §4), as `CouponRules::refusalNow` reads the same facts: ended once
 * its end has come or its uses are all taken — whatever its switch; then switched off; then not yet
 * started; else active.
 */
final class CouponStates
{
    public static function of(bool $active, DateTimeImmutable $startsAt, ?DateTimeImmutable $endsAt, int $usesTotal, ?int $maxUsesTotal, DateTimeImmutable $now): CouponState
    {
        return match (true) {
            ($endsAt !== null && $now >= $endsAt) || ($maxUsesTotal !== null && $usesTotal >= $maxUsesTotal) => CouponState::Ended,
            ! $active => CouponState::Inactive,
            $now < $startsAt => CouponState::Scheduled,
            default => CouponState::Active,
        };
    }
}
