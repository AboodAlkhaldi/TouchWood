<?php

declare(strict_types=1);

namespace Modules\Promotions\Public\Enums;

/**
 * Why a coupon is refused whole, never trimmed (promotions.md §1.3, §2.2) — the checkout shows each its
 * own message. Checked in this order, so a coupon both ended and used up says it ended.
 */
enum CouponRefusal: string
{
    case UnknownCode = 'UNKNOWN_CODE';
    case Inactive = 'INACTIVE';
    case NotStarted = 'NOT_STARTED';
    case Ended = 'ENDED';
    case UsedUp = 'USED_UP';
    case UsedUpForYou = 'USED_UP_FOR_YOU';
    case NotAssignedToYou = 'NOT_ASSIGNED_TO_YOU';
    case BelowMinimum = 'BELOW_MINIMUM';
    case NothingEligible = 'NOTHING_ELIGIBLE';
    case WorthNothing = 'WORTH_NOTHING';
    /** Only when the coupon is used with points (`useCoupon`): at a quote the coupon is decided first. */
    case NotWithPoints = 'NOT_WITH_POINTS';
    case PassesCeiling = 'PASSES_CEILING';
}
