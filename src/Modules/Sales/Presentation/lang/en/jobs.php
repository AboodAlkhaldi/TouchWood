<?php

declare(strict_types=1);

// What Sales's queued work is called on the failed jobs screen (frontend.md E7).
return [
    'delete_expired_quotes' => 'Deleting expired checkouts',
    'empty_old_carts' => 'Emptying carts left alone for 90 days',
    'forget_customer' => "Forgetting an anonymized account: its carts deleted, its orders' email cleared",
];
