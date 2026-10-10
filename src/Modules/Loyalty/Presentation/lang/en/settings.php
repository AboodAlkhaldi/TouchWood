<?php

declare(strict_types=1);

// The names of the points programme's settings, as the settings screen shows them
// (ProgrammeSettings, loyalty.md §1.5). One set per store; only an admin changes them.
return [
    'module' => 'Points',

    'points.enabled' => 'Points Programme On',
    'points.earn_per_unit' => 'Points Earned per 1 Unit of Currency Spent',
    'points.points_per_unit_off' => 'Points for 1 Unit of Currency Off',
    'points.expiry_months' => 'Points Last (Months)',
    'points.minimum_redemption' => 'Fewest Points in One Redemption',
    'points.max_redemption_percent' => 'Most of an Order\'s Subtotal Points May Pay (%)',
    'redemption.public_partial' => 'Individuals Choose How Many Points to Use',
    'redemption.company_partial' => 'Companies Choose How Many Points to Use',
];
