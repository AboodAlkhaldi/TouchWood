<?php

declare(strict_types=1);

// The unsubscribe pages (ops.md §1.3, §3; owner, 2026-10-30): one button each. NOT YET APPROVED by
// the owner.
return [
    'unsubscribe' => [
        'title' => 'Unsubscribe from offers and news',
        'text' => 'Stop receiving offers and news by email. Messages about your orders and your account still come.',
        'action' => 'Unsubscribe',
        'done' => 'You are unsubscribed. You can switch offers and news back on in your account at any time.',
    ],
    'leave' => [
        'title' => 'Leave the newsletter',
        'text' => 'Stop receiving our newsletter at this email address.',
        'action' => 'Unsubscribe',
        'done' => 'You are unsubscribed. You can sign up again at any time.',
    ],
];
