<?php

declare(strict_types=1);

return [
    'store_not_found' => [
        'title' => 'Store Not Found',
        'detail' => 'There is no store with the code ":code".',
    ],
    'store_code_taken' => [
        'title' => 'Store Code Already Used',
        'detail' => 'Another store already uses the code ":code".',
    ],
    'store_attribute_immutable' => [
        'title' => 'Cannot Be Changed',
        'detail' => 'A store\'s :attribute cannot be changed after it is created.',
    ],
    'invalid_store_attribute' => [
        'title' => 'Invalid Store Details',
        'detail' => 'The store :attribute is not valid.',
    ],
    'invalid_tax_rate' => [
        'title' => 'Invalid Tax Rate',
        'detail' => 'The tax rate must be between 0% and 100%.',
    ],
    'invalid_timezone' => [
        'title' => 'Invalid Timezone',
        'detail' => '":timezone" is not a valid timezone.',
    ],
    'currency_not_found' => [
        'title' => 'Currency Not Found',
        'detail' => 'There is no currency with the code ":code".',
    ],
    'currency_already_exists' => [
        'title' => 'Currency Already Exists',
        'detail' => 'The currency ":code" already exists.',
    ],
    'currency_exponent_locked' => [
        'title' => 'Cannot Change Decimal Places',
        'detail' => 'The decimal places of ":code" cannot change because a store already uses it.',
    ],
    'invalid_currency_attribute' => [
        'title' => 'Invalid Currency Details',
        'detail' => 'The currency :attribute is not valid.',
    ],
    'unknown_setting' => [
        'title' => 'Unknown Setting',
        'detail' => 'There is no setting named ":key".',
    ],
    'invalid_setting_value' => [
        'title' => 'Invalid Setting Value',
        'detail' => 'The value for ":key" is not valid.',
    ],
    'setting_scope_mismatch' => [
        'title' => 'Wrong Setting Scope',
        'detail' => 'The setting ":key" cannot be used this way: check whether it is set per store or for all stores.',
    ],
    'media_not_found' => [
        'title' => 'File Not Found',
        'detail' => 'The file does not exist.',
    ],
    'failed_job_not_found' => [
        'title' => 'Job Not Found',
        'detail' => 'This failed job is no longer waiting: it was retried or deleted.',
    ],
    'failed_job_not_retryable' => [
        'title' => 'Cannot Be Retried',
        'detail' => 'This job failed on a queue that cannot take it back safely. Delete it instead.',
    ],
    'unsupported_media_type' => [
        'title' => 'File Type Not Allowed',
        'detail' => 'Images must be JPEG, PNG or WebP; documents must be PDF, JPEG or PNG.',
    ],
    'media_too_large' => [
        'title' => 'File Too Large',
        'detail' => 'The file is larger than the upload limit.',
    ],
    'media_in_use' => [
        'title' => 'File in Use',
        'detail' => 'The file is still used and cannot be deleted: :uses.',
    ],
    'invalid_media_variants_transition' => [
        'title' => 'Cannot Retry',
        'detail' => 'Only an image whose sizes failed to generate can be retried.',
    ],
    'invalid_media_attribute' => [
        'title' => 'Invalid File Details',
        'detail' => 'The :attribute of the file is not valid.',
    ],
    'missing_translation' => [
        'title' => 'Translation Missing',
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
        'visibility' => 'visibility',
    ],
];
