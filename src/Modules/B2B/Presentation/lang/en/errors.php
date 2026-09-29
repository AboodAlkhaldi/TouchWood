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
    'not_a_company_account' => [
        'title' => 'Company accounts only',
        'detail' => 'Only a company account can apply as a company.',
    ],
    'company_not_found' => [
        'title' => 'No company yet',
        'detail' => 'There is no company for this account until its first application is sent.',
    ],
    'application_not_found' => [
        'title' => 'No application',
        'detail' => 'There is no application to change. Start one first.',
    ],
    'application_already_open' => [
        'title' => 'Already waiting',
        'detail' => 'Your application is waiting for a decision. You can start another once it is decided.',
    ],
    'email_not_verified' => [
        'title' => 'Confirm your email',
        'detail' => 'Confirm your email address before you send the application.',
    ],
    'document_type_inactive' => [
        'title' => 'Document not accepted',
        'detail' => 'This kind of document is not accepted. Choose one from the list.',
    ],
    'application_file_not_found' => [
        'title' => 'File not found',
        'detail' => 'That file is not in any of your applications.',
    ],
    'type_not_found' => [
        'title' => 'Type not found',
        'detail' => 'There is no such type in your stores\' lists.',
    ],
    // Amendment 13(b), the owner's words.
    'company_type_not_set' => [
        'title' => 'Choose a listed type first',
        'detail' => 'Choose a listed type for this company before approving it.',
    ],
    // Amendment 13(e).
    'company_account_deleted' => [
        'title' => 'Account deleted',
        'detail' => 'The account was deleted: reject this application.',
    ],
    'type_name_taken' => [
        'title' => 'Name already used',
        'detail' => 'Another type in this list already has that name, in Arabic or in English.',
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
        'replacement' => 'replacement type',
        'requests' => 'requested items',
        'status' => 'status',
        'store' => 'store',
        'target' => 'type to move the companies to',
        'tax_number' => 'tax number',
    ],
];
