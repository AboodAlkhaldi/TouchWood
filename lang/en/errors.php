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
        // A shopper's headings where the panel's would read as the system's (owner, 2026-10-20); 404 keeps
        // the status's, "Page Not Found".
        'title' => [
            409 => 'Something Changed',
            413 => 'File Too Large',
            415 => 'File Type Not Accepted',
            422 => 'Check the Details',
        ],
        // Each ends with the fix (frontend.md §1.10). A page refused (403) says the 404's title and sentence,
        // so a refused page can't be told from a missing one (owner, 2026-10-20).
        'sentence' => [
            404 => 'This page isn\'t available. Check the address, or go back to the store.',
            409 => 'Couldn\'t open this page: it changed while it loaded. Reload it and try again.',
            413 => 'Couldn\'t upload the file: it\'s too large. Choose a smaller file.',
            415 => 'Couldn\'t upload the file: this type isn\'t accepted. Choose another file.',
            422 => 'Couldn\'t continue: some details aren\'t valid. Check them and try again.',
        ],
    ],
];
