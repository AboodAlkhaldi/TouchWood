<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Model;

/**
 * A banner's or a pop-up's state (content.md §4.1): `DRAFT` until scheduled, then `SCHEDULED`, `LIVE` from its
 * start, `ENDED` from its end. Only the draft mark is stored; the rest is read from the dates and the clock.
 */
enum ScheduleState: string
{
    case Draft = 'DRAFT';
    case Scheduled = 'SCHEDULED';
    case Live = 'LIVE';
    case Ended = 'ENDED';
}
