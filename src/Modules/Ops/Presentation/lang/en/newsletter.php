<?php

declare(strict_types=1);

// The shop's newsletter box and the page its confirmation link opens (ops.md §1.9). Wording approved for
// now (owner, 2026-11-01; full review at the end).
return [
    'box' => [
        'title' => 'Our Newsletter',
        'text' => 'Get our offers and news by email.',
        'email' => 'Email address',
        'action' => 'Subscribe',
        'sent' => 'Check your email: open the link we sent to finish subscribing.',
        // New (2026-11-05), not yet approved (ops.md §4.15 O11; owner, 2026-11-05).
        'sign_in' => 'Sign In',
        'too_many' => [
            'one' => 'Too many tries. Try again in :count minute.',
            'other' => 'Too many tries. Try again in :count minutes.',
        ],
        'failed' => 'Couldn\'t subscribe just now. Try again.',
    ],
    'confirm' => [
        'title' => 'Confirm Your Subscription',
        'text' => 'Confirm that you want to receive our newsletter at this email address.',
        'action' => 'Confirm',
        'done' => 'You are subscribed. Every newsletter has a link to unsubscribe.',
        // New (2026-11-05), not yet approved (ops.md §4.15 O13; owner, 2026-11-05).
        'shop' => 'Go to the Shop',
        'failed' => 'That didn\'t go through. Try again.',
    ],
];
