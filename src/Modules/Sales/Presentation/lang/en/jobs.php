<?php

declare(strict_types=1);

// What Sales's queued work is called on the failed jobs screen (frontend.md E7).
return [
    'complete_try_when_paid' => 'Making the order of a paid online checkout',
    'release_try_when_not_paid' => 'Giving back what a failed online payment reserved',
    'withdraw_request_when_cancelled' => "Withdrawing a cancelled order's payment request",
    'release_try_when_not_accepted' => 'Giving back what a payment in another currency reserved',
    'review_checkout_tries' => 'Looking at online payments past their time',
    'delete_expired_quotes' => 'Deleting expired checkouts',
    'empty_old_carts' => 'Emptying carts left alone for 90 days',
    'flag_short_orders' => 'Marking orders whose stock was reduced',
    'forget_customer' => "Forgetting an anonymized account: its carts deleted, its orders' email cleared",
    'push_sales_ranks' => "Ranking each store's best-selling products",
];
