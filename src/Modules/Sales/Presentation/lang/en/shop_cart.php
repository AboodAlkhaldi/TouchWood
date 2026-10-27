<?php

declare(strict_types=1);

// Sales in the shop: the cart, its lines and their fixes (sales.md §4.1 S3, S4).
return [
    'title' => 'Cart',
    'header' => 'Cart',
    'pieces' => ['zero' => ':count pieces', 'one' => ':count piece', 'two' => ':count pieces', 'few' => ':count pieces', 'many' => ':count pieces', 'other' => ':count pieces'],
    'empty' => 'Your cart is empty',
    'continue' => 'Continue Shopping',
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
        'sign_in' => 'Sign in to use a coupon',
    ],
    'summary' => [
        'title' => 'Summary',
        'subtotal' => 'Subtotal',
        'later' => 'The coupon, points, shipping and VAT are worked out at checkout.',
        'checkout' => 'Checkout',
        'fix_first' => 'Fix the marked lines first',
        'may_not_order' => 'Your account can\'t place orders yet.',
        'finish_account' => 'Finish your account',
        'sign_in_first' => 'Sign in or register to check out. Your cart comes with you.',
    ],
    'toast' => [
        'added' => 'Added to cart.',
        'removed' => 'Removed from your cart.',
        'coupon_kept' => 'Coupon kept for checkout.',
        'coupon_cleared' => 'Coupon removed.',
    ],
];
