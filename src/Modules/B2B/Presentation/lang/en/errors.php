<?php

declare(strict_types=1);

// B2B's error messages, by error type (b2b.{key}).
return [
    'invalid_company_attribute' => [
        'title' => 'Check the company details',
        'detail' => 'The :attribute is not valid.',
    ],
    'application_not_editable' => [
        'title' => 'Already sent',
        'detail' => 'This application has been sent and can no longer be changed.',
    ],
    'missing_required_document' => [
        'title' => 'A document is missing',
        'detail' => 'Attach every required document before you send the application.',
    ],
    'company_type_inactive' => [
        'title' => 'Choose the company type again',
        'detail' => 'The company type you chose is no longer offered. Choose another, or Other.',
    ],
    'company_suspended' => [
        'title' => 'Company suspended',
        'detail' => 'While the company is suspended, its registered details cannot be changed.',
    ],
    'invalid_company_status' => [
        'title' => 'Nothing to change',
        'detail' => 'The company is not in a state that allows that change.',
    ],
    'fields' => [
        'address' => 'address',
        'company_type' => 'company type',
        'company_type_other' => 'company type',
        'cr_number' => 'commercial registration number',
        'name' => 'company name',
        'name_ar' => 'Arabic name',
        'name_en' => 'English name',
        'note' => 'note',
        'position' => 'position',
        'reason' => 'reason',
        'tax_number' => 'tax number',
    ],
];
