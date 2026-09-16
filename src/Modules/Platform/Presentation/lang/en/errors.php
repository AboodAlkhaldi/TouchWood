<?php

return [
    'store_not_found' => [
        'title' => 'Store not found',
        'detail' => 'There is no store with the code ":code".',
    ],
    'store_code_taken' => [
        'title' => 'Store code already used',
        'detail' => 'Another store already uses the code ":code".',
    ],
    'store_attribute_immutable' => [
        'title' => 'Cannot be changed',
        'detail' => 'A store\'s :attribute cannot be changed after it is created.',
    ],
    'invalid_store_attribute' => [
        'title' => 'Invalid store details',
        'detail' => 'The store :attribute is not valid.',
    ],
    'invalid_tax_rate' => [
        'title' => 'Invalid tax rate',
        'detail' => 'The tax rate must be between 0% and 100%.',
    ],
    'invalid_timezone' => [
        'title' => 'Invalid timezone',
        'detail' => '":timezone" is not a valid timezone.',
    ],
    'currency_not_found' => [
        'title' => 'Currency not found',
        'detail' => 'There is no currency with the code ":code".',
    ],
    'currency_already_exists' => [
        'title' => 'Currency already exists',
        'detail' => 'The currency ":code" already exists.',
    ],
    'currency_exponent_locked' => [
        'title' => 'Cannot change decimal places',
        'detail' => 'The decimal places of ":code" cannot change because a store already uses it.',
    ],
    'invalid_currency_attribute' => [
        'title' => 'Invalid currency details',
        'detail' => 'The currency :attribute is not valid.',
    ],
    'unknown_setting' => [
        'title' => 'Unknown setting',
        'detail' => 'There is no setting named ":key".',
    ],
    'invalid_setting_value' => [
        'title' => 'Invalid setting value',
        'detail' => 'The value for ":key" is not valid.',
    ],
    'setting_scope_mismatch' => [
        'title' => 'Wrong setting scope',
        'detail' => 'The setting ":key" cannot be used this way: check whether it is set per store or for all stores.',
    ],
    'missing_translation' => [
        'title' => 'Translation missing',
        'detail' => 'The :attribute needs both an Arabic and an English value.',
    ],
];
