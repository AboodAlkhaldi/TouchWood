<?php

declare(strict_types=1);

// Payments' queued work as the failed jobs screen names it (platform.md §3).
return [
    'warn_expiring_keys' => 'Warning the Super Admins of payment gateway keys about to expire',
    'check_attempt' => 'Asking the payment gateway about a payment',
    'handle_notice' => 'Acting on a notice from the payment gateway',
    'sweep_attempts' => 'Looking for payment checks that were lost',
    'clear_customer_from_attempts' => "Removing an anonymized customer's details from their payment attempts",
];
