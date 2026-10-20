<?php

declare(strict_types=1);

// Sync's error messages, by error type (sync.{key}), and the names of the fields they point at.
return [
    'connection_failed' => [
        'title' => 'The provider could not be read',
        'detail' => 'Failed to read the provider: :reason',
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
        'detail' => 'This read isn\'t available.',
    ],
    'size_not_found' => [
        'title' => 'Size gone',
        'detail' => 'This size no longer exists. Dismiss the entry instead.',
    ],
    'entry_not_dismissable' => [
        'title' => 'Closes by itself',
        'detail' => 'A failed read closes when a read of the store works again. Press Retry or Sync now.',
    ],
    'entry_not_retryable' => [
        'title' => 'Not read again',
        'detail' => 'A read never closes this entry. Open the offer or the product instead, or dismiss the entry.',
    ],
    'store_off' => [
        'title' => 'This store is off',
        'detail' => 'Only someone who may switch stores on and off works in the Sync screens of a store that is off.',
    ],
    'invalid_sync_input' => [
        'title' => 'Check the details',
        'detail' => 'The :attribute is not valid.',
        // One of the provider's own settings, named as the provider names it (Q34).
        'rules' => [
            'setting' => 'The provider\'s setting ":setting" is not valid.',
            // The address, by the rule it broke (sync.md §2.4).
            'address_https' => 'The address must start with https://.',
            'address_malformed' => 'The address can\'t be called: check its host and its port.',
            'address_query' => 'Odoo\'s address can\'t hold a query (?) or a fragment (#).',
            // A key or a secret left empty while the address changes (owner, 2026-10-20).
            'key_again' => 'Type the key again: the address changed.',
            'secret_again' => 'Type the secret again: the address changed.',
            'address_path' => 'Odoo\'s address must end in /touchwood/v1.',
        ],
    ],

    'fields' => [
        'address' => 'address',
        'after' => 'place in the list',
        'key' => 'key',
        'kind' => 'kind of entry',
        'provider' => 'provider',
        'secret' => 'notification secret',
        'section' => 'section',
        'settings' => 'provider\'s setting',
        'store' => 'store',
    ],
];
