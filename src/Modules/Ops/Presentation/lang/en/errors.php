<?php

declare(strict_types=1);

// Ops' error messages, by error type (ops.{key}), and the names of the fields they point at (ops.md §7).
return [
    'inbox_entry_not_found' => [
        'title' => 'Notification Not Found',
        'detail' => 'This notification isn\'t in your list.',
    ],
    'invalid_ops_input' => [
        'title' => 'Check the Details',
        'detail' => 'The :attribute isn\'t valid. Check it and try again.',
    ],

    'fields' => [
        'after' => 'place in the list',
        'entry' => 'notification',
        'kind' => 'kind',
        'title_ar' => 'Arabic title',
        'title_en' => 'English title',
        'line_ar' => 'Arabic line',
        'line_en' => 'English line',
        'link' => 'link',
        'person' => 'person',
        'channel' => 'channel',
        'provider_id' => 'provider id',
    ],
];
