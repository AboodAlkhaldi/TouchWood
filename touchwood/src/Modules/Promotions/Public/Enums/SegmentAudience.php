<?php

declare(strict_types=1);

namespace Modules\Promotions\Public\Enums;

/**
 * Whom a segment holds (promotions.md §1.5): individuals or companies — the account's type in Access.
 * Set when the segment is made, never changed (owner, 2026-10-18, answer 23).
 */
enum SegmentAudience: string
{
    case Individual = 'INDIVIDUAL';
    case Company = 'COMPANY';
}
