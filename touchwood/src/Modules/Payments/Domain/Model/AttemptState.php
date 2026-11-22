<?php

declare(strict_types=1);

namespace Modules\Payments\Domain\Model;

/**
 * An attempt's states (payments.md §4). `Creating` was prepared and never charged; `Charging` is claimed,
 * so nothing can end it as "never charged" (rule 4).
 */
enum AttemptState: string
{
    case Creating = 'CREATING';
    case Charging = 'CHARGING';
    case Open = 'OPEN';
    case Unknown = 'UNKNOWN';
    case Succeeded = 'SUCCEEDED';
    case Failed = 'FAILED';
    case Refused = 'REFUSED';
    case Expired = 'EXPIRED';
    case Abandoned = 'ABANDONED';

    /** Still waiting on the gateway's word: the checks go on, and its try's reservations stay. */
    public function isLive(): bool
    {
        return in_array($this, [self::Charging, self::Open, self::Unknown], true);
    }
}
