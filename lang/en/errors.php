<?php

declare(strict_types=1);

/*
| Messages for errors owned by the Shared kernel and for generic HTTP failures.
| Each module keeps its own errors under its own translation namespace.
*/

return [
    'category' => [
        'not_found' => 'Not Found',
        'forbidden' => 'Not Allowed',
        'conflict' => 'Conflict',
        'invalid' => 'Invalid Request',
        'unsupported' => 'Unsupported',
        'too_large' => 'Too Large',
    ],

    'unauthorized' => [
        'title' => 'Not Allowed',
        'detail' => 'Couldn\'t do that: it isn\'t one of your jobs. Ask an administrator for it.',
    ],

    'invalid_money' => [
        'title' => 'Invalid Amount',
        'detail' => 'Couldn\'t use this amount: it isn\'t valid. Check it and try again.',
    ],

    'validation_failed' => [
        'title' => 'Invalid Data',
        'detail' => 'Couldn\'t save: some of what you entered isn\'t valid. Check the marked fields.',
    ],

    'http' => [
        400 => 'Bad Request',
        403 => 'Not Allowed',
        404 => 'Page Not Found',
        405 => 'Method Not Allowed',
        409 => 'Conflict',
        413 => 'Too Large',
        415 => 'Unsupported',
        419 => 'Session Expired',
        422 => 'Invalid Request',
        429 => 'Too Many Requests',
        500 => 'Server Error',
        503 => 'Service Unavailable',
    ],

    // The admin panel's error page says the error's own sentence under its title; an error that wrote
    // none - an address that names nothing, say - says its status's (frontend.md §2.6).
    'page' => [
        // A page refused on permission reads as a page, not an action (owner, 2026-10-20).
        403 => 'You don\'t have access to this page.',
        404 => 'There\'s nothing at this address: it was removed, or the link is wrong.',
        409 => 'Couldn\'t open this page: something changed meanwhile. Reload it and try again.',
        413 => 'Couldn\'t open this page: what was sent is too large.',
        415 => 'Couldn\'t open this page: what was sent isn\'t a kind it takes.',
        422 => 'Couldn\'t open this page: something in its address isn\'t valid.',
    ],

    // The shop's error page (frontend.md §2.6): the same, in a shopper's words rather than the panel's
    // (owner, 2026-10-20), and its way back - the store's home. Its page ships this file.
    'shop' => [
        'back' => 'Back to the Store',
        'sentence' => [
            // [PROVISIONAL: what a refusal on permission means to a shopper - frontend.md §2.6]
            403 => 'You can\'t open this page.',
            404 => 'This page isn\'t available.',
            409 => 'Couldn\'t open this page: it changed while it loaded. Reload it and try again.',
            413 => 'Couldn\'t open this page: the request is too large.',
            415 => 'Couldn\'t open this page: the request isn\'t in a form it accepts.',
            422 => 'Couldn\'t open this page: its address isn\'t valid. Check the link and try again.',
        ],
    ],
];
