<?php

declare(strict_types=1);

namespace Modules\Loyalty\Public\Enums;

/**
 * How a buyer redeems (loyalty.md §1.5, §1.7): choosing how many points to use, or their whole balance
 * or none. Each store sets it per audience.
 */
enum RedemptionMode: string
{
    case Partial = 'PARTIAL';
    case AllOrNothing = 'ALL_OR_NOTHING';
}
