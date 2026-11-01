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
    ],
    'confirm' => [
        'title' => 'Confirm Your Subscription',
        'text' => 'Confirm that you want to receive our newsletter at this email address.',
        'action' => 'Confirm',
        'done' => 'You are subscribed. Every newsletter has a link to unsubscribe.',
    ],
];
