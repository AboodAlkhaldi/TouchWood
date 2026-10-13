<?php

declare(strict_types=1);

namespace Modules\Loyalty\Domain\Model\Settlement;

/**
 * What covered a point a reversed lot could not give back itself (loyalty.md §1.3, §1.8 step 2c).
 */
enum CoverSource: string
{
    /** The order's own given-back points that came back already expired: nothing moves. */
    case ExpiredPoints = 'EXPIRED_POINTS';

    /** Points taken from another lot, by a COVER take. */
    case Lot = 'LOT';

    /** Nothing was left to cover it: dropped (§1.4). */
    case Dropped = 'DROPPED';
}
