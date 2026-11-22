<?php

declare(strict_types=1);

namespace Modules\Promotions\Public\Enums;

/**
 * A coupon's state as staff read it (promotions.md §4) — derived from its dates, uses and switch, never
 * stored. An ended coupon stays ended whatever its switch; a switched-off one that has not ended is
 * inactive; then before its start, scheduled.
 */
enum CouponState: string
{
    case Scheduled = 'SCHEDULED';
    case Active = 'ACTIVE';
    case Ended = 'ENDED';
    case Inactive = 'INACTIVE';
}
