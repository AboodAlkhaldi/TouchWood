<?php

declare(strict_types=1);

namespace Modules\Promotions\Public\Enums;

/** Who may use a coupon (promotions.md §1.1): anyone who types it, or only the customers it was assigned to. */
enum CouponEligibility: string
{
    case Public = 'PUBLIC';
    case Assigned = 'ASSIGNED';
}
