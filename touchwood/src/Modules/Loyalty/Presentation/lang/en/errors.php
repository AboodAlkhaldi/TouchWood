<?php

declare(strict_types=1);

// Loyalty's error messages, by error type (loyalty.{key}), and the names of the fields they point at
// (loyalty.md §7).
return [
    'points_programme_off' => [
        'title' => 'Points Are Off',
        'detail' => 'Points can\'t be used in this store right now.',
    ],
    'redemption_refused' => [
        'title' => 'Points Can\'t Be Used',
        'detail' => 'Your points can\'t be used on this order. Check your points and try again.',
    ],
    'redemption_changed' => [
        'title' => 'Your Points Discount Changed',
        'detail' => 'Your points discount changed since checkout worked it out. Check it and place the order again.',
    ],
    'points_held' => [
        'title' => 'Points Held for a Payment',
        'detail' => 'Some of the customer\'s points are held for a payment in progress. Try again in a few minutes, or remove fewer.',
    ],
    'deduction_too_large' => [
        'title' => 'Not Enough Points',
        'detail' => 'The customer doesn\'t have that many points. Remove fewer.',
    ],
    'points_account_not_found' => [
        'title' => 'Points Not Found',
        'detail' => 'No points were found for this customer in this store.',
    ],
    'order_not_settleable' => [
        'title' => 'Order Can\'t Be Settled',
        'detail' => 'This order\'s points can\'t be settled that way.',
    ],
    'invalid_points_attribute' => [
        'title' => 'Check the Points Details',
        'detail' => 'The :attribute isn\'t valid. Check it and try again.',
    ],

    'fields' => [
        'points' => 'number of points',
        'reason' => 'reason',
        'returned_amounts' => 'returned amounts',
        'currency' => 'currency',
        'store' => 'store',
        'permission' => 'permission',
        'amount' => 'amount',
        'percent' => 'percentage',
        'rate' => 'points rate',
        'customer' => 'customer',
        'order' => 'order',
        'return' => 'return',
        'edit' => 'order edit',
        'order_number' => 'order number',
    ],
];
