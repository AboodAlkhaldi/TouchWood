<?php

declare(strict_types=1);

namespace Modules\Pricing\Public\Enums;

/**
 * Which lines a campaign's prices apply to (pricing.md §9.6 #2; content.md Q3 (a)): retail lines,
 * wholesale lines, or both - as a category discount's (§1.5). A wholesale line pays the lower of the
 * campaign and its band.
 */
enum CampaignLines: string
{
    case Retail = 'RETAIL';
    case Wholesale = 'WHOLESALE';
    case Both = 'BOTH';
}
