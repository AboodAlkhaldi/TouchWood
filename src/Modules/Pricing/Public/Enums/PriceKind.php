<?php

declare(strict_types=1);

namespace Modules\Pricing\Public\Enums;

/**
 * The kinds of price (pricing.md §1.3; handoff §10.1, revised 2026-10-07). The lowest that applies to a
 * line wins; nothing stacks. Quantity prices apply to wholesale lines only.
 */
enum PriceKind: string
{
    case Base = 'BASE';
    case Sale = 'SALE';
    case Campaign = 'CAMPAIGN';
    case Category = 'CATEGORY';
    case Quantity = 'QUANTITY';
}
