<?php

declare(strict_types=1);

namespace Modules\Platform\Public\Enums;

/**
 * Where on the admin home a card is drawn (dashboard.md §2): in the greeting row as a button (Today's Report,
 * §2.1), among the figures of the period (§2 B-I), or under Needs Attention (§2 J) - where every card built
 * before the dashboard sits, so it is the default.
 */
enum HomeBand: string
{
    case Greeting = 'greeting';
    case Overview = 'overview';
    case Attention = 'attention';
}
