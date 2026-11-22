<?php

declare(strict_types=1);

namespace Modules\Sales\Public\Enums;

/**
 * Why an order was cancelled (sales.md §1.6; owner, 2026-10-09): a customer picks one of the list, or
 * `OTHER` with their words; a staff member's is always `BY_STAFF`, with theirs.
 */
enum CancelReason: string
{
    case ChangedMind = 'CHANGED_MIND';
    case OrderedByMistake = 'ORDERED_BY_MISTAKE';
    case FoundCheaper = 'FOUND_CHEAPER';
    case TooSlow = 'TOO_SLOW';
    case Other = 'OTHER';
    case ByStaff = 'BY_STAFF';
}
