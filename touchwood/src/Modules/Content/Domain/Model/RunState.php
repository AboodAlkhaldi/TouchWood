<?php

declare(strict_types=1);

namespace Modules\Content\Domain\Model;

/**
 * Where a campaign's run stands (content.md §4.1): `DRAFT` → `SCHEDULED` → `LIVE` (by the job's conditional update
 * alone, §1.7 #9) → `ENDED`; `SCHEDULED` → `CANCELLED`. Stored, unlike a banner's: going live fires the notice once.
 */
enum RunState: string
{
    case Draft = 'DRAFT';
    case Scheduled = 'SCHEDULED';
    case Live = 'LIVE';
    case Ended = 'ENDED';
    case Cancelled = 'CANCELLED';

    /** Ended or cancelled: history, never revived (§1.7 #8). */
    public function isOver(): bool
    {
        return $this === self::Ended || $this === self::Cancelled;
    }
}
