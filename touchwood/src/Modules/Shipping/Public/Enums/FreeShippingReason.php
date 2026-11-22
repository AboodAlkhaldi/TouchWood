<?php

declare(strict_types=1);

namespace Modules\Shipping\Public\Enums;

/**
 * Why a carrier's fee is 0 for this order (shipping.md §1.5): its own free-above amount, or the
 * store-wide one that overrides every carrier's while it is set.
 */
enum FreeShippingReason: string
{
    case Carrier = 'CARRIER';
    case Store = 'STORE';
}
