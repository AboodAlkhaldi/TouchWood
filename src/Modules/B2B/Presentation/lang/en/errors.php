<?php

declare(strict_types=1);

// B2B's error messages, by error type (b2b.{key}).
return [
    'invalid_company_attribute' => [
        'title' => 'Check the Company Details',
        'detail' => 'Couldn\'t save: the :attribute isn\'t valid. Check it and try again.',
    ],
    'application_not_editable' => [
        'title' => 'Already Sent',
        'detail' => 'Couldn\'t change the application: it has been sent. Wait for the decision.',
    ],
    'missing_required_document' => [
        'title' => 'A Document Is Missing',
        'detail' => 'Couldn\'t send the application: a required document is missing. Attach every required document.',
    ],
    'company_type_inactive' => [
        'title' => 'Choose the Company Type Again',
        'detail' => 'Couldn\'t use this company type: it is no longer offered. Choose another type.',
    ],
    'company_suspended' => [
        'title' => 'Company Suspended',
        'detail' => 'Couldn\'t change the company details: the company is suspended. Change them once it is reinstated.',
    ],
    'invalid_company_status' => [
        'title' => 'Nothing to Change',
        'detail' => 'Couldn\'t make that change: the company\'s state doesn\'t allow it. Reload the page.',
    ],
    'flagged_item_not_replaced' => [
        'title' => 'Replace the Marked Items',
        'detail' => 'Replace every item marked in the last decision before you send the application.',
    ],
    'request_not_answered' => [
        'title' => 'A Request Is Not Answered',
        'detail' => 'Couldn\'t send the application: a request in the last decision isn\'t answered. Answer every request.',
    ],
    'document_no_longer_accepted' => [
        'title' => 'A Document Is No Longer Accepted',
        'detail' => 'Couldn\'t send the application: a document is no longer accepted. Remove every document marked so.',
    ],
    'request_not_found' => [
        'title' => 'Request Not Found',
        'detail' => 'Couldn\'t find that request in the last decision. Reload the page.',
    ],
    'answer_kind_mismatch' => [
        'title' => 'Answer as Asked',
        'detail' => 'Couldn\'t save the answer. Answer the way the request asks: with text, or with a file.',
    ],
    'not_a_company_account' => [
        'title' => 'Company Accounts Only',
        'detail' => 'Couldn\'t apply: only a company account can apply as a company. Register a company account.',
    ],
    'company_not_found' => [
        'title' => 'No Company Yet',
        'detail' => 'Couldn\'t find the company: there is none until its first application is sent. Reload the page.',
    ],
    'application_not_found' => [
        'title' => 'No Application',
        'detail' => 'Couldn\'t find an application to change. Start one first.',
    ],
    'application_already_open' => [
        'title' => 'Already Waiting',
        'detail' => 'Couldn\'t start another application: yours is waiting for a decision. Start another once it is decided.',
    ],
    'email_not_verified' => [
        'title' => 'Confirm Your Email',
        'detail' => 'Couldn\'t send the application: your email address isn\'t confirmed. Confirm it, then send.',
    ],
    'document_type_inactive' => [
        'title' => 'Document Not Accepted',
        'detail' => 'Couldn\'t attach the file: this kind of document isn\'t accepted. Choose one from the list.',
    ],
    'duplicate_document_file' => [
        'title' => 'The Same File Twice',
        'detail' => 'Couldn\'t attach the file: one with this name is already under another document. Choose the right file for this one.',
    ],
    'application_file_not_found' => [
        'title' => 'File Not Found',
        'detail' => 'Couldn\'t find that file in your applications. Reload the page.',
    ],
    'type_not_found' => [
        'title' => 'Type Not Found',
        'detail' => 'Couldn\'t find that type in your stores\' lists. Reload the page.',
    ],
    // Amendment 13(b), the owner's words.
    'company_type_not_set' => [
        'title' => 'Choose a Listed Type First',
        'detail' => 'Couldn\'t approve the company: it has no listed type. Correct its type first.',
    ],
    // Amendment 13(e).
    'company_account_deleted' => [
        'title' => 'Account Deleted',
        'detail' => 'Couldn\'t approve the company: its account was deleted. Reject the application instead.',
    ],
    'type_name_taken' => [
        'title' => 'Name Already Used',
        'detail' => 'Couldn\'t save the type: another one in this list has that name, in Arabic or in English. Choose another name.',
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
