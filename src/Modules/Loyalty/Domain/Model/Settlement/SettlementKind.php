<?php

declare(strict_types=1);

namespace Modules\Loyalty\Domain\Model\Settlement;

/** What is being settled (loyalty.md §1.8). */
enum SettlementKind: string
{
    /** Before shipping: everything comes back; the order is cancelled. */
    case Cancellation = 'CANCELLATION';

    /** After delivery: by running totals, keyed by the return's id. */
    case Return = 'RETURN';

    /** A staff edit before shipping that lowered the points discount, keyed by the edit's id. */
    case Edit = 'EDIT';
}
