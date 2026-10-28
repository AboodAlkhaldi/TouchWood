<?php

declare(strict_types=1);

// The names of Sales's settings, as the settings screen shows them (SalesSettings, sales.md §1.10).
return [
    'module' => 'Orders',

    'shipping.flat_fee' => 'Shipping Fee (in the Currency\'s Smallest Unit)',
    'shipping.free_above' => 'Free Shipping From (in the Currency\'s Smallest Unit; 0 Means Never)',
    'returns.window_days' => 'Return Window (Days After Delivery)',
    'coupons.wrong_codes' => 'Wrong Coupon Codes Allowed (Per Customer, Every Store)',
    'coupons.window_minutes' => 'Wrong Coupon Codes Window (Minutes, Every Store)',
];
