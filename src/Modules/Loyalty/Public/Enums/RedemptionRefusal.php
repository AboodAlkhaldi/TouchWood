<?php

declare(strict_types=1);

namespace Modules\Loyalty\Public\Enums;

/**
 * Why points cannot be used on this order (loyalty.md §1.7, §2.2), in the order the rules are read.
 * A quote answers it; placing such a redemption is refused with it.
 */
enum RedemptionRefusal: string
{
    /** The store's programme is off. */
    case ProgrammeOff = 'PROGRAMME_OFF';

    /** The balance is 0. */
    case NothingToRedeem = 'NOTHING_TO_REDEEM';

    /** A PARTIAL ask outside 1 to the balance. */
    case OutOfRange = 'OUT_OF_RANGE';

    /** The cap allows no discount on this order. */
    case NothingUnderCap = 'NOTHING_UNDER_CAP';

    /** The points that count are worth nothing. */
    case WorthNothing = 'WORTH_NOTHING';

    /** The points charged would not reach the store's minimum. */
    case BelowMinimum = 'BELOW_MINIMUM';
}
