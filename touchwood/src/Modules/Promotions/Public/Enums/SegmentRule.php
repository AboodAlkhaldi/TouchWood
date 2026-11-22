<?php

declare(strict_types=1);

namespace Modules\Promotions\Public\Enums;

/**
 * A dynamic segment's one rule (promotions.md §1.5), over the store's placed orders — every one counts,
 * whatever happened to it (owner, 2026-10-08).
 */
enum SegmentRule: string
{
    /** Never ordered in the store — only customers registered in it. */
    case NoOrdersEver = 'NO_ORDERS_EVER';

    /** Their last order in the store is more than M months old; someone who never ordered there is not in it. */
    case NoOrderFor = 'NO_ORDER_FOR';

    /** N or more orders in the store in the last M months; "monthly buyers" are N = M. */
    case AtLeastOrders = 'AT_LEAST_ORDERS';
}
