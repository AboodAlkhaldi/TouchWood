<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Enums;

/**
 * How much of a row of the admin home a card takes on a wide screen (dashboard.md §6.1): the page lays the cards
 * out by position and size, so §2's order reads as drawn. On a phone every card is the whole width.
 *
 * Half is the builder's addition to the spec's four, for the Needs Attention cards built before the dashboard,
 * which were drawn two to a row.
 */
enum HomeSize: string
{
    case Full = 'full';
    case TwoThirds = 'two_thirds';
    case Half = 'half';
    case Third = 'third';
    case Quarter = 'quarter';
}
