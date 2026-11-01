<?php

declare(strict_types=1);

// The names of Payments' permissions (PaymentsPermissions), in the role editor's Payments group.
return [
    'gateway' => [
        'manage' => 'Set up the store\'s payment gateway',
    ],
    'bank_account' => [
        'update' => 'Change the store\'s bank account for transfers',
    ],
    'payment' => [
        'view' => 'View the store\'s online payments',
    ],
];
