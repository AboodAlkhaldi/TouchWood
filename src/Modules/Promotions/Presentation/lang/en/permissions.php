<?php

declare(strict_types=1);

// The names of Promotions' permissions (PromotionsPermissions): the four jobs the role editor shows
// under Marketing, a customer's own coupons, and the nightly refresh reserved to the system.
return [
    'marketing' => [
        'view' => 'View the store\'s coupons, segments and gifts',
    ],
    'coupon' => [
        'manage' => 'Manage the store\'s coupons',
        'view_own' => 'View my coupons',
    ],
    'segment' => [
        'manage' => 'Manage the store\'s customer segments',
        'refresh' => 'Refresh every segment',
    ],
    'gift' => [
        'manage' => 'Manage the store\'s gift levels',
    ],
];
