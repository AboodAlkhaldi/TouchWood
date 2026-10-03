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
        'detail' => 'You do not have permission to do this.',
    ],

    'invalid_money' => [
        'title' => 'Invalid Amount',
        'detail' => 'The amount is not valid.',
    ],

    'validation_failed' => [
        'title' => 'Invalid Data',
        'detail' => 'Some of the information you entered is not valid.',
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
