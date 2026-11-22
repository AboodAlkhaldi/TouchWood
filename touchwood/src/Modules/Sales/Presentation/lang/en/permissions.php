<?php

declare(strict_types=1);

// The names of Sales's permissions (SalesPermissions): what every guest and customer may do in a
// store's shop, the jobs the role editor shows under Orders, the admin-only settings, the system's work.
return [
    'cart' => [
        'manage' => 'Keep my cart',
    ],
    'guest_cart' => [
        'manage' => 'Keep a cart as a guest',
    ],
    'order' => [
        'place' => 'Place orders',
        'manage_own' => 'Cancel my orders and ask for returns',
        'view_own' => 'View my orders and returns',
        'view' => 'View the store\'s orders and returns',
        'approve' => 'Approve or reject orders',
        'edit' => 'Change orders before they ship',
        'cancel' => 'Cancel orders',
        'fulfil' => 'Ship orders and mark them delivered',
    ],
    'payment' => [
        'record' => 'Record payments and refunds',
    ],
    'return' => [
        'manage' => 'Handle returns',
    ],
    'settings' => [
        'update' => 'Change the shipping fee and the return window',
    ],
    'system' => [
        'run' => 'Run the orders\' scheduled work',
    ],
];
