<?php

declare(strict_types=1);

return [
    'base_store_always_active' => [
        'title' => 'Base Store Is Always On',
        'detail' => 'Couldn\'t turn off the store ":code". The base store is always on.',
    ],
    'store_not_found' => [
        'title' => 'Store Not Found',
        'detail' => 'Couldn\'t find a store with the code ":code". Check the code and try again.',
    ],
    'store_code_taken' => [
        'title' => 'Store Code Already Used',
        'detail' => 'Couldn\'t use the code ":code": another store already has it. Choose another code.',
    ],
    'store_attribute_immutable' => [
        'title' => 'Can\'t Be Changed',
        'detail' => 'Couldn\'t change the store\'s :attribute: it is fixed once the store is created. Leave it as it is.',
    ],
    'invalid_store_attribute' => [
        'title' => 'Invalid Store Details',
        'detail' => 'Couldn\'t save the store: the :attribute isn\'t valid. Check it and try again.',
    ],
    'invalid_tax_rate' => [
        'title' => 'Invalid Tax Rate',
        'detail' => 'Couldn\'t save the tax rate: it must be between 0% and 100%. Enter a rate in that range.',
    ],
    'invalid_timezone' => [
        'title' => 'Invalid Time Zone',
        'detail' => 'Couldn\'t save the time zone: ":timezone" isn\'t one. Choose one from the list.',
    ],
    'currency_not_found' => [
        'title' => 'Currency Not Found',
        'detail' => 'Couldn\'t find a currency with the code ":code". Check the code and try again.',
    ],
    'currency_already_exists' => [
        'title' => 'Currency Already Exists',
        'detail' => 'Couldn\'t add ":code": that currency already exists. Edit it instead.',
    ],
    'currency_exponent_locked' => [
        'title' => 'Decimal Places Locked',
        'detail' => 'Couldn\'t change the decimal places of ":code": a store already uses it. Leave them as they are.',
    ],
    'invalid_currency_attribute' => [
        'title' => 'Invalid Currency Details',
        'detail' => 'Couldn\'t save the currency: the :attribute isn\'t valid. Check it and try again.',
    ],
    'unknown_setting' => [
        'title' => 'Unknown Setting',
        'detail' => 'Couldn\'t find a setting named ":key". Reload the page.',
    ],
    'invalid_setting_value' => [
        'title' => 'Invalid Setting Value',
        'detail' => 'Couldn\'t save ":key": the value isn\'t valid. Check it and try again.',
    ],
    'setting_scope_mismatch' => [
        'title' => 'Wrong Setting Scope',
        'detail' => 'Couldn\'t save ":key" this way. Check whether it is set per store or for all stores.',
    ],
    'media_not_found' => [
        'title' => 'File Not Found',
        'detail' => 'Couldn\'t find this file. Reload the page.',
    ],
    'failed_job_not_found' => [
        'title' => 'Job Not Found',
        'detail' => 'Couldn\'t find this failed job: it was retried or deleted. Go back to the list.',
    ],
    'failed_job_not_retryable' => [
        'title' => 'Can\'t Be Retried',
        'detail' => 'Couldn\'t retry this job: its queue can\'t take it back safely. Delete it instead.',
    ],
    'unsupported_media_type' => [
        'title' => 'File Type Not Allowed',
        'detail' => 'Couldn\'t upload the file: images must be JPEG, PNG or WebP, and documents PDF, JPEG or PNG. Choose a file of one of these types.',
    ],
    'media_too_large' => [
        'title' => 'File Too Large',
        'detail' => 'Couldn\'t upload the file: it is larger than the upload limit. Choose a smaller file.',
    ],
    'media_in_use' => [
        'title' => 'File in Use',
        'detail' => 'Couldn\'t delete the file: it is still used (:uses). Remove it from those places first.',
    ],
    'invalid_media_variants_transition' => [
        'title' => 'Can\'t Retry',
        'detail' => 'Couldn\'t retry: only an image whose sizes failed to generate can be retried. Reload the page.',
    ],
    'invalid_media_attribute' => [
        'title' => 'Invalid File Details',
        'detail' => 'Couldn\'t save the file: its :attribute isn\'t valid. Check it and try again.',
    ],
    'missing_translation' => [
        'title' => 'Translation Missing',
        'detail' => 'Couldn\'t save: the :attribute needs both an Arabic and an English value. Fill in both.',
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
        'visibility' => 'visibility',
    ],
];
