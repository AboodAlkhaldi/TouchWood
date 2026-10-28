<?php

declare(strict_types=1);

// Sales's error messages, by error type (sales.{key}), and the names of the fields they point at
// (sales.md §7).
return [
    'may_not_order' => [
        'title' => 'You Can\'t Order Yet',
        'detail' => 'Your account can\'t place orders in this store now. Check your account page for what\'s missing.',
    ],
    'not_for_your_account' => [
        'title' => 'Not Sold to Your Account',
        'detail' => 'This product isn\'t sold to your type of account.',
    ],
    'not_orderable' => [
        'title' => 'Can\'t Be Ordered',
        'detail' => 'One of the products can\'t be ordered as it is. Check its quantity and try again.',
    ],
    'address_not_usable' => [
        'title' => 'Address Can\'t Be Used',
        'detail' => 'Choose a delivery address in this store, or complete the one you chose.',
    ],
    'way_to_pay_not_offered' => [
        'title' => 'Way to Pay Not Offered',
        'detail' => 'This way to pay isn\'t offered for your account. Choose another.',
    ],
    'sign_in_to_use_coupon' => [
        'title' => 'Sign In to Use a Coupon',
        'detail' => 'Sign in or create an account to use a coupon.',
    ],
    'too_many_coupon_tries' => [
        'title' => 'Too Many Wrong Codes',
        'detail' => 'Couldn\'t try the code: too many codes that match no coupon. Try again in :minutes minutes.',
    ],
    'too_many_waiting_orders' => [
        'title' => 'Too Many Orders Waiting',
        'detail' => 'You already have :most orders waiting for approval in this store. You can order again once the store approves or rejects one of them, or you cancel one.',
    ],
    'quote_not_found' => [
        'title' => 'Checkout Not Found',
        'detail' => 'This checkout was not found. Start the checkout again.',
    ],
    'quote_expired' => [
        'title' => 'Checkout Expired',
        'detail' => 'Your checkout expired. We\'ve worked it out again — check it and place the order.',
    ],
    'quote_changed' => [
        'title' => 'Your Checkout Changed',
        'detail' => 'Something in your order changed since you started. Check the new totals and place the order again.',
    ],
    'not_cancellable' => [
        'title' => 'Can\'t Be Cancelled',
        'detail' => 'This order can no longer be cancelled.',
    ],
    'not_editable' => [
        'title' => 'Can\'t Be Changed',
        'detail' => 'This order can no longer be changed.',
    ],
    'not_shippable' => [
        'title' => 'Can\'t Ship Yet',
        'detail' => 'This order can\'t be shipped yet: it isn\'t approved, or it isn\'t paid and wasn\'t allowed to ship unpaid.',
    ],
    'return_not_allowed' => [
        'title' => 'Return Not Allowed',
        'detail' => 'You can\'t return this: the return window has passed, or there are more pieces or photos than allowed.',
    ],
    'too_many_files' => [
        'title' => 'Can\'t Add More Files',
        'detail' => 'No more documents can be added to this order.',
    ],
    'refund_too_large' => [
        'title' => 'Refund Too Large',
        'detail' => 'The refund is more than what was paid on this order and not yet refunded.',
    ],
    'invalid_order_change' => [
        'title' => 'Can\'t Do That Now',
        'detail' => 'This can\'t be done in the order\'s current state. Refresh the page and try again.',
    ],
    'order_not_found' => [
        'title' => 'Order Not Found',
        'detail' => 'No such order was found.',
    ],
    'return_not_found' => [
        'title' => 'Return Not Found',
        'detail' => 'No such return was found.',
    ],
    'invalid_sales_attribute' => [
        'title' => 'Check the Details',
        'detail' => 'The :attribute isn\'t valid. Check it and try again.',
    ],

    'fields' => [
        'store' => 'store',
        'quantity' => 'quantity',
        'variant' => 'size',
        'sale_mode' => 'retail or wholesale',
        'coupon' => 'coupon',
        'address' => 'address',
        'way_to_pay' => 'way to pay',
        'reason' => 'reason',
        'amount' => 'amount',
        'locale' => 'language',
        'points' => 'points',
        'way' => 'way',
        'reference' => 'reference',
        'note' => 'note',
        'date' => 'date',
        'request' => 'form',
        'file' => 'file',
        'carrier' => 'carrier',
        'tracking_number' => 'tracking number',
        'gift' => 'gift',
        'lines' => 'pieces asked',
        'filter' => 'filter',
        'status' => 'status',
        'search' => 'search',
    ],
];
