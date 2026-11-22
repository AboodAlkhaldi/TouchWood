<?php

declare(strict_types=1);

namespace Modules\Pricing\Public\Enums;

/**
 * What a campaign's price is set on (pricing.md §9.6 #1): a product, a size, a category (and every one
 * below it) or a brand - each a percentage off; a size may take a fixed price instead.
 */
enum CampaignTargetKind: string
{
    case Product = 'PRODUCT';
    case Size = 'SIZE';
    case Category = 'CATEGORY';
    case Brand = 'BRAND';
}
