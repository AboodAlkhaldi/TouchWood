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
        419 => 'Session Expired',
        429 => 'Too Many Requests',
        500 => 'Server Error',
        503 => 'Service Unavailable',
    ],
];
