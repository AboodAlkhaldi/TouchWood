<?php

declare(strict_types=1);

// Sync's error messages, by error type (sync.{key}), and the names of the fields they point at.
return [
    'connection_failed' => [
        'title' => 'The provider could not be read',
        'detail' => 'Nothing was saved. The reason: :reason',
    ],
    'already_wired' => [
        'title' => 'Already wired',
        'detail' => 'This store is wired to a provider already. Change its connection instead.',
    ],
    'not_wired' => [
        'title' => 'Not wired',
        'detail' => 'This store is not wired to a provider.',
    ],
    'entry_not_found' => [
        'title' => 'Not found',
        'detail' => 'There is no such entry in the Sync report.',
    ],
    'read_not_found' => [
        'title' => 'Not found',
        'detail' => 'There is no such read. Reads are kept 90 days.',
    ],
    'size_not_found' => [
        'title' => 'Size gone',
        'detail' => 'This size no longer exists. Dismiss the entry instead.',
    ],
    'entry_not_dismissable' => [
        'title' => 'Closes by itself',
        'detail' => 'A failed read closes when a read of the store works again. Press Retry or Sync now.',
    ],
    'invalid_sync_input' => [
        'title' => 'Check the details',
        'detail' => 'The :attribute is not valid.',
    ],

    'fields' => [
        'address' => 'address',
        'key' => 'key',
        'kind' => 'kind of entry',
        'provider' => 'provider',
        'secret' => 'notification secret',
        'store' => 'store',
    ],
];
