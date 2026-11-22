<?php

declare(strict_types=1);

namespace Modules\Promotions\Public\Enums;

/**
 * How a segment gets its members (promotions.md §1.5): picked by hand, or filled by one rule at each
 * refresh. Set when the segment is made, never changed (owner, 2026-10-18, answer 23).
 */
enum SegmentType: string
{
    case Static = 'STATIC';
    case Dynamic = 'DYNAMIC';
}
