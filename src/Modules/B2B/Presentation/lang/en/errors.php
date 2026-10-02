<?php

declare(strict_types=1);

// B2B's error messages, by error type (b2b.{key}).
return [
    'invalid_company_attribute' => [
        'title' => 'Check the Company Details',
        'detail' => 'The :attribute is not valid.',
    ],
    'application_not_editable' => [
        'title' => 'Already Sent',
        'detail' => 'This application has been sent and can no longer be changed.',
    ],
    'missing_required_document' => [
        'title' => 'A Document Is Missing',
        'detail' => 'Attach every required document before you send the application.',
    ],
    'company_type_inactive' => [
        'title' => 'Choose the Company Type Again',
        'detail' => 'The company type you chose is no longer offered. Choose another, or Other.',
    ],
    'company_suspended' => [
        'title' => 'Company Suspended',
        'detail' => 'While the company is suspended, its registered details cannot be changed.',
    ],
    'invalid_company_status' => [
        'title' => 'Nothing to Change',
        'detail' => 'The company is not in a state that allows that change.',
    ],
    'flagged_item_not_replaced' => [
        'title' => 'Replace the Marked Items',
        'detail' => 'Replace every item marked in the last decision before you send the application.',
    ],
    'request_not_answered' => [
        'title' => 'A Request Is Not Answered',
        'detail' => 'Answer every request in the last decision before you send the application.',
    ],
    'document_no_longer_accepted' => [
        'title' => 'A Document Is No Longer Accepted',
        'detail' => 'Remove every document marked as no longer accepted before you send the application.',
    ],
    'request_not_found' => [
        'title' => 'Request Not Found',
        'detail' => 'That request is not one of the last decision\'s.',
    ],
    'answer_kind_mismatch' => [
        'title' => 'Answer as Asked',
        'detail' => 'Answer this request the way it asks: with text, or with a file.',
    ],
    'not_a_company_account' => [
        'title' => 'Company Accounts Only',
        'detail' => 'Only a company account can apply as a company.',
    ],
    'company_not_found' => [
        'title' => 'No Company Yet',
        'detail' => 'There is no company for this account until its first application is sent.',
    ],
    'application_not_found' => [
        'title' => 'No application',
        'detail' => 'There is no application to change. Start one first.',
    ],
    'application_already_open' => [
        'title' => 'Already Waiting',
        'detail' => 'Your application is waiting for a decision. You can start another once it is decided.',
    ],
    'email_not_verified' => [
        'title' => 'Confirm Your Email',
        'detail' => 'Confirm your email address before you send the application.',
    ],
    'document_type_inactive' => [
        'title' => 'Document Not Accepted',
        'detail' => 'This kind of document is not accepted. Choose one from the list.',
    ],
    'duplicate_document_file' => [
        'title' => 'The Same File Twice',
        'detail' => 'A file with this name is already under another document. Choose the right file for this one.',
    ],
    'application_file_not_found' => [
        'title' => 'File Not Found',
        'detail' => 'That file is not in any of your applications.',
    ],
    'type_not_found' => [
        'title' => 'Type Not Found',
        'detail' => 'There is no such type in your stores\' lists.',
    ],
    // Amendment 13(b), the owner's words.
    'company_type_not_set' => [
        'title' => 'Choose a Listed Type First',
        'detail' => 'Choose a listed type for this company before approving it.',
    ],
    // Amendment 13(e).
    'company_account_deleted' => [
        'title' => 'Account Deleted',
        'detail' => 'The account was deleted: reject this application.',
    ],
    'type_name_taken' => [
        'title' => 'Name Already Used',
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
