<?php

declare(strict_types=1);

// The Provider page (sync.md §4.1 Y3), a Super Admin's. Plurals in the six forms the page picks from.
return [
    'title' => 'Provider',
    'subtitle' => 'Where this store\'s prices and stock come from.',

    // Until the Odoo connection is built (Q38).
    'not_ready' => 'The Odoo connection is being built. Wiring a store works once it is ready.',

    'wire' => [
        'title' => 'Wire Store',
        'submit' => 'Test and Wire Store',
        'testing' => 'Testing…',
    ],
    'change' => [
        'submit' => 'Test and Save Connection',
        'cancel' => 'Cancel',
        'keep_key' => 'Leave empty to keep the current key.',
        'keep_secret' => 'Leave empty to keep the current secret.',
    ],

    'fields' => [
        'provider' => 'Provider',
        'address' => 'Address',
        'address_placeholder' => 'https://odoo.example.com/touchwood/v1',
        'key' => 'Key',
        'secret' => 'Secret',
        'settings' => 'The provider\'s settings',
    ],

    // Checked as typed; the server decides.
    'checks' => [
        'address_https' => 'The address must start with https://.',
        'address_host' => 'Address needs a host.',
        'address_plain' => 'Address can\'t hold spaces, a name or a password.',
        'address_long' => 'Address can be at most 500 characters.',
        'key_long' => 'The key can be at most 1,000 characters.',
        'secret_long' => 'The secret can be at most 1,000 characters.',
    ],

    'wired' => [
        'provider' => 'Provider',
        'address' => 'Address',
        'settings' => 'Settings',
        'key_and_secret' => 'Key and Secret',
        'set' => 'Set',
        'last_changed' => 'Last Changed',
        'state' => 'State',
        'last_read' => 'Last Read',
        'last_full_read' => 'Last Full Read',
        'never' => 'Not yet',
        'notice_address' => 'Notice Address',
        'copy_notice' => 'Copy Notice Address',
        'notice_sends' => 'The provider sends:',
        'notice_timestamp' => 'X-TouchWood-Timestamp: the time, in Unix seconds',
        'notice_signature' => 'X-TouchWood-Signature: an HMAC-SHA256 of "timestamp.body" with the secret, in hexadecimal',
        'notice_body' => 'The body {"codes": [...]}, 1 to :most codes',
    ],

    'menu' => [
        'change' => 'Change Connection',
        'unwire' => 'Unwire Store…',
    ],

    'unwire' => [
        'title' => 'Unwire Store',
        'stops' => [
            'zero' => 'No size stops being orderable.',
            'one' => ':count size stops being orderable: with no provider every size counts on its stock, and it has none free.',
            'two' => ':count sizes stop being orderable: with no provider every size counts on its stock, and these have none free.',
            'few' => ':count sizes stop being orderable: with no provider every size counts on its stock, and these have none free.',
            'many' => ':count sizes stop being orderable: with no provider every size counts on its stock, and these have none free.',
            'other' => ':count sizes stop being orderable: with no provider every size counts on its stock, and these have none free.',
        ],
        'stays' => 'Prices and stock the provider wrote stay. Staff set them here again from now on.',
        // Read as: To confirm, type the store's code "sa" (ui.to_confirm_named).
        'code_label' => 'store\'s code',
        'confirm' => 'Unwire Store',
    ],

    'toast' => [
        'store_wired' => 'Store wired',
        'connection_changed' => 'Connection changed',
        'store_unwired' => 'Store unwired',
    ],
];
