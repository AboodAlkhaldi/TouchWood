<?php

declare(strict_types=1);

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
    'media_not_found' => [
        'title' => 'File not found',
        'detail' => 'The file does not exist.',
    ],
    'unsupported_media_type' => [
        'title' => 'File type not allowed',
        'detail' => 'Images must be JPEG, PNG or WebP; documents must be PDF, JPEG or PNG.',
    ],
    'media_too_large' => [
        'title' => 'File too large',
        'detail' => 'The file is larger than the upload limit.',
    ],
    'media_in_use' => [
        'title' => 'File in use',
        'detail' => 'The file is still used and cannot be deleted: :uses.',
    ],
    'invalid_media_variants_transition' => [
        'title' => 'Cannot retry',
        'detail' => 'Only an image whose sizes failed to generate can be retried.',
    ],
    'invalid_media_attribute' => [
        'title' => 'Invalid file details',
        'detail' => 'The :attribute of the file is not valid.',
    ],
    'missing_translation' => [
        'title' => 'Translation missing',
        'detail' => 'The :attribute needs both an Arabic and an English value.',
    ],
    /* Field names, for the refusals that name one. */
    'fields' => [
        'bytes' => 'size',
        'checksum' => 'checksum',
        'code' => 'code',
        'country' => 'country',
        'exponent' => 'decimal places',
        'original_filename' => 'file name',
        'permission' => 'permission',
        'position' => 'position',
        'sign' => 'symbol',
    ],
];
