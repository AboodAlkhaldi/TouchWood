<?php

declare(strict_types=1);

// Promotions' error messages, by error type (promotions.{key}), and the names of the fields they point
// at (promotions.md §7).
return [
    'coupon_refused' => [
        'title' => 'Coupon Can\'t Be Used',
        'detail' => 'This coupon can\'t be used on this order any more. Remove it and place the order again.',
    ],
    'coupon_changed' => [
        'title' => 'Your Coupon Changed',
        'detail' => 'Your coupon\'s discount changed since checkout worked it out. Check it and place the order again.',
    ],
    'code_taken' => [
        'title' => 'Code Already Taken',
        'detail' => 'Another coupon in this store already has the code :code. Choose another code.',
    ],
    'coupon_in_use' => [
        'title' => 'Coupon Already Used',
        'detail' => 'This coupon has already been used, so its uses can\'t be removed or set below those already made.',
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
        'gift_variant' => 'gift',
        'percent' => 'percentage',
        'amount' => 'amount',
        'dates' => 'date range',
        'store' => 'store',
        'permission' => 'permission',
        'customer' => 'customer',
        'currency' => 'currency',
        'line' => 'cart line',
        'order' => 'order',
        'order_number' => 'order number',
        'coupon' => 'coupon',
        'edit' => 'order edit',
        'name' => 'name',
        'eligibility' => 'who may use it',
        'discount_kind' => 'kind of discount',
        'cap' => 'most it takes off',
        'minimum' => 'minimum order',
        'max_uses' => 'number of uses',
        'sale_modes' => 'retail and wholesale choice',
        'buyers' => 'buyers',
        'rules' => 'product lists',
        'product' => 'product',
        'category' => 'category',
        'brand' => 'brand',
        'segment_type' => 'kind of segment',
        'audience' => 'who it holds',
        'rule' => 'rule',
        'rule_months' => 'number of months',
        'rule_orders' => 'number of orders',
        'members' => 'customers picked',
        'segment' => 'segment',
        'limit' => 'page size',
        'after' => 'page position',
    ],
];
