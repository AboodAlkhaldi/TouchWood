<?php

declare(strict_types=1);

// Promotions' error messages, by error type (promotions.{key}), and the names of the fields they point
// at (promotions.md §7).
return [
    'coupon_changed' => [
        'title' => 'Your Coupon Changed',
        'detail' => 'Your coupon\'s discount changed since checkout worked it out. Check it and place the order again.',
    ],
    'code_taken' => [
        'title' => 'Code Already Used',
        'detail' => 'Another coupon in this store already has the code :code. Choose another code.',
    ],
    'coupon_in_use' => [
        'title' => 'Coupon Already Used',
        'detail' => 'This coupon has been used, so it can\'t be changed that way. Switch it off instead.',
    ],
    'gift_level_clash' => [
        'title' => 'Gift Level Already Set',
        'detail' => 'Another active gift level has the same amount for the same buyers and dates.',
    ],
    'coupon_not_found' => [
        'title' => 'Coupon Not Found',
        'detail' => 'No such coupon was found in this store.',
    ],
    'segment_not_found' => [
        'title' => 'Segment Not Found',
        'detail' => 'No such segment was found in this store.',
    ],
    'gift_level_not_found' => [
        'title' => 'Gift Level Not Found',
        'detail' => 'No such gift level was found in this store.',
    ],
    'invalid_promotion_attribute' => [
        'title' => 'Check the Details',
        'detail' => 'The :attribute isn\'t valid. Check it and try again.',
    ],

    'fields' => [
        'code' => 'code',
        'percent' => 'percentage',
        'amount' => 'amount',
        'dates' => 'dates',
        'store' => 'store',
        'permission' => 'permission',
    ],
];
