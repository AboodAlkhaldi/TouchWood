<?php

declare(strict_types=1);

namespace Modules\Pricing\Domain\ValueObject;

/**
 * How the lists of sales and category discounts are ordered (pricing.md §4.3.3 P2, P3; owner Q7,
 * 2026-10-19): **by state** - running, the one ending soonest first; then scheduled, the one starting
 * soonest first; then ended, the one ended last first - or **newest added** first.
 */
enum WindowOrder: string
{
    case ByState = 'BY_STATE';
    case Newest = 'NEWEST';
}
