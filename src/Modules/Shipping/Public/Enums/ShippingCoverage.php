<?php

declare(strict_types=1);

namespace Modules\Shipping\Public\Enums;

/**
 * Where an address stands for a store (shipping.md §1.2): the country first, then the place.
 */
enum ShippingCoverage: string
{
    /** This store ships there and a switched-on carrier has a price for the place. */
    case Served = 'SERVED';
    /** This store ships to the country, but no switched-on carrier prices the place: staff set the fee. */
    case NotCovered = 'NOT_COVERED';
    /** No store of ours ships to the country: placed and handled by staff, as NOT_COVERED. */
    case Outside = 'OUTSIDE';
    /** Another of our stores ships to the country: refused, naming that store. */
    case OtherStore = 'OTHER_STORE';
}
