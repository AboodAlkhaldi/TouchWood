<?php

declare(strict_types=1);

// The names of Loyalty's permissions (LoyaltyPermissions): a customer's own points, the three jobs
// the role editor shows under Points, and the nightly expiry reserved to the system.
return [
    'points' => [
        'view_own' => 'View my points',
        'view' => 'View customers\' points and the store\'s redemptions',
        'adjust' => 'Add or remove customers\' points by hand',
        'expire' => 'Expire points whose date has passed',
    ],
    'settings' => [
        'update' => 'Change the store\'s points programme',
    ],
];
