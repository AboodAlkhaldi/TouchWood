<?php

declare(strict_types=1);

// Payments' error messages, by error type (payments.{key}), and the names of the fields they point at
// (payments.md §7).
return [
    'gateway_unreachable' => [
        'title' => 'Payment Gateway Not Answering',
        'detail' => 'The payment gateway did not answer. Try again in a few minutes.',
    ],
    'key_test_failed' => [
        'title' => 'Key Not Accepted',
        'detail' => 'MyFatoorah did not accept this key (:reason). Nothing was saved.',
    ],
    'key_permission_missing' => [
        'title' => 'Key Missing a Permission',
        'detail' => 'This key may not use :permission. Switch that permission on for the key in MyFatoorah\'s portal, then paste it again. Nothing was saved.',
    ],
    'gateway_not_set_up' => [
        'title' => 'No Payment Gateway Yet',
        'detail' => 'This store has no payment gateway yet. Save a key to set it up.',
    ],
    'no_gateway_key' => [
        'title' => 'No Key for This Mode',
        'detail' => 'The :mode mode has no key yet. Save its key and secret first.',
    ],
    'gateway_not_offered' => [
        'title' => 'Gateway Not Offered Here',
        'detail' => 'MyFatoorah is offered for the stores in Saudi Arabia and Egypt only.',
    ],
    'hold_too_short' => [
        'title' => 'Hold Limit Too Short',
        'detail' => 'The hold limit (:hold minutes) must be longer than the bank page time and the wait together (:window + :wait minutes).',
    ],
    'invalid_payments_input' => [
        'title' => 'Check the Details',
        'detail' => 'The :field isn\'t valid. Check it and try again.',
    ],
    'fields' => [
        'store' => 'store',
        'mode' => 'test or live',
        'key' => 'API key',
        'secret' => 'notice secret',
        'expires_on' => 'key\'s expiry date',
    ],
];
