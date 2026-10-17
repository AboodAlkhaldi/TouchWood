<?php

declare(strict_types=1);

namespace Modules\Promotions\Public\Enums;

/**
 * What a coupon takes off (promotions.md §1.1, owner 2026-10-08): a percentage of the eligible lines,
 * with an optional cap, or a fixed amount, never more than their total.
 */
enum DiscountKind: string
{
    case Percent = 'PERCENT';
    case Fixed = 'FIXED';
}
