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
    'flagged_item_not_replaced' => [
        'title' => 'Replace the marked items',
        'detail' => 'Replace every item marked in the last decision before you send the application.',
    ],
    'request_not_answered' => [
        'title' => 'A request is not answered',
        'detail' => 'Answer every request in the last decision before you send the application.',
    ],
    'document_no_longer_accepted' => [
        'title' => 'A document is no longer accepted',
        'detail' => 'Remove every document marked as no longer accepted before you send the application.',
    ],
    'request_not_found' => [
        'title' => 'Request not found',
        'detail' => 'That request is not one of the last decision\'s.',
    ],
    'answer_kind_mismatch' => [
        'title' => 'Answer as asked',
        'detail' => 'Answer this request the way it asks: with text, or with a file.',
    ],
    'fields' => [
        'address' => 'address',
        'answer' => 'answer',
        'company_type' => 'company type',
        'company_type_other' => 'company type',
        'cr_number' => 'commercial registration number',
        'flags' => 'marked items',
        'label' => 'label',
        'name' => 'company name',
        'name_ar' => 'Arabic name',
        'name_en' => 'English name',
        'note' => 'note',
        'position' => 'position',
        'reason' => 'reason',
        'tax_number' => 'tax number',
    ],
];
