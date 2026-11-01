<?php

declare(strict_types=1);

// Sales in the shop: the cart, its lines and their fixes (sales.md §4.1 S3, S4).
return [
    'title' => 'Cart',
    'header' => 'Cart',
    'pieces' => ['zero' => ':count pieces', 'one' => ':count piece', 'two' => ':count pieces', 'few' => ':count pieces', 'many' => ':count pieces', 'other' => ':count pieces'],
    'empty' => 'Your cart is empty',
    'continue' => 'Continue Shopping',
    'best_sellers' => 'Best Sellers',
    'mode' => ['RETAIL' => 'Retail', 'WHOLESALE' => 'Wholesale'],
    'line' => [
        'lines' => 'Items in your cart',
        'quantity' => 'Pieces of :name',
        'less' => 'One piece less',
        'more' => 'One piece more',
        'remove' => 'Remove',
        'unit' => 'Each',
        'no_price' => 'No price yet',
        'limits' => ':minimum to :maximum pieces',
        'from' => 'At least :minimum pieces',
    ],
    'problem' => [
        'NOT_AVAILABLE' => 'No longer available.',
        'NOT_FOR_YOUR_ACCOUNT' => 'Not sold to your account.',
        'BELOW_MINIMUM' => 'At least :minimum pieces.',
        'ABOVE_MAXIMUM' => 'At most :maximum pieces.',
        'OUT_OF_STOCK' => 'Out of stock.',
        'NOT_PRICED' => 'Not priced yet.',
        'set_to' => 'Set to :quantity',
    ],
    'notice' => [
        'title' => 'Some items left your cart when you signed in',
        'WHOLESALE' => 'Wholesale is for company accounts.',
        'NOT_FOR_YOUR_ACCOUNT' => 'Not sold to your account.',
        'close' => 'Close',
    ],
    'coupon' => [
        'label' => 'Coupon code',
        'apply' => 'Apply',
        'remove' => 'Remove',
        'kept' => 'Coupon :code is applied at checkout.',
        'kept_for' => 'applied at checkout.',
        'sign_in' => 'Sign in to use a coupon',
    ],
    'summary' => [
        'title' => 'Summary',
        'subtotal' => 'Subtotal',
        'later' => 'The coupon, points and VAT are worked out at checkout.',
        'shipping' => 'Shipping',
        'shipping_later' => 'Worked out at checkout',
        'checkout' => 'Checkout',
        'fix_first' => 'Fix the marked lines first',
        'sign_in_first' => 'Sign in or register to check out. Your cart comes with you.',
    ],
    // Why this account may not order yet, and its one fix (R1-17; §4.2 B12).
    'block' => [
        'ACCOUNT_NOT_ACTIVE' => 'Your account can\'t place orders. Contact the store for help.',
        'EMAIL_UNVERIFIED' => 'Verify your email to place orders.',
        'PHONE_COUNTRY_NOT_SERVED' => 'We can\'t take orders with a phone number from this country. Change your phone number to order.',
        'PHONE_UNVERIFIED' => 'Verify your phone number to place orders.',
        'COMPANY_NOT_APPROVED' => 'Your company must be approved here before it can order.',
        'fix' => [
            'EMAIL_UNVERIFIED' => 'Verify Email',
            'PHONE_COUNTRY_NOT_SERVED' => 'Change Phone',
            'PHONE_UNVERIFIED' => 'Verify Phone',
            'COMPANY_NOT_APPROVED' => 'View Your Company',
        ],
    ],
    // Free shipping, the next gift and the points (R1-12 to R1-14).
    'extras' => [
        'add_more' => 'Add',
        'for_free_shipping' => 'more for free shipping.',
        'ships_free' => 'Your order ships free.',
        'free_bar' => 'Toward free shipping',
        'spend_about' => 'Spend about',
        'for_a_gift' => 'more for a free gift.',
        'points' => ['zero' => 'You\'ll earn :count points with this order.', 'one' => 'You\'ll earn :count point with this order.', 'two' => 'You\'ll earn :count points with this order.', 'few' => 'You\'ll earn :count points with this order.', 'many' => 'You\'ll earn :count points with this order.', 'other' => 'You\'ll earn :count points with this order.'],
    ],
    'skipped' => [
        'title' => 'Some items could not be added again',
        'NOT_AVAILABLE' => 'no longer available.',
        'NOT_FOR_YOUR_ACCOUNT' => 'not sold to your account.',
    ],
    'toast' => [
        'added' => 'Added to cart.',
        'removed' => 'Removed from your cart.',
        'coupon_kept' => 'Coupon kept for checkout.',
        'coupon_cleared' => 'Coupon removed.',
    ],
];
