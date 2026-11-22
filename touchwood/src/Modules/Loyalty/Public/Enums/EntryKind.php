<?php

declare(strict_types=1);

namespace Modules\Loyalty\Public\Enums;

/**
 * The kinds of ledger entry (loyalty.md §1.2), each with its sign. An entry never holds 0 points.
 */
enum EntryKind: string
{
    /** + An order delivered. */
    case Earned = 'EARNED';

    /** − Checkout, inside placing the order. */
    case Redeemed = 'REDEEMED';

    /** + The order that used them cancelled or returned. */
    case RedemptionReturned = 'REDEMPTION_RETURNED';

    /** − The order that earned them cancelled or returned. */
    case EarningReversed = 'EARNING_REVERSED';

    /** + An admin, by hand, with a reason. */
    case Added = 'ADDED';

    /** − An admin, by hand, with a reason. */
    case Deducted = 'DEDUCTED';

    /** − A lot's date passing, or points coming back to a lot whose date has passed. */
    case Expired = 'EXPIRED';
}
