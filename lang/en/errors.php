<?php

/*
| Messages for errors owned by the Shared kernel and for generic HTTP failures.
| Each module keeps its own errors under its own translation namespace.
*/

return [
    'category' => [
        'not_found' => 'Not found',
        'forbidden' => 'Not allowed',
        'conflict' => 'Conflict',
        'invalid' => 'Invalid request',
        'unsupported' => 'Unsupported',
        'too_large' => 'Too large',
    ],

    'unauthorized' => [
        'title' => 'Not allowed',
        'detail' => 'You do not have permission to do this.',
    ],

    'invalid_money' => [
        'title' => 'Invalid amount',
        'detail' => 'The amount is not valid.',
    ],

    'validation_failed' => [
        'title' => 'Invalid data',
        'detail' => 'Some of the information you entered is not valid.',
    ],

    'http' => [
        400 => 'Bad request',
        403 => 'Not allowed',
        404 => 'Page not found',
        405 => 'Method not allowed',
        419 => 'Your session has expired',
        429 => 'Too many requests',
        500 => 'Something went wrong',
        503 => 'Service unavailable',
    ],
];
