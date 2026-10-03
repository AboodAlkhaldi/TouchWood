<?php

declare(strict_types=1);

// The names of B2B's permissions (B2BPermissions). The company's own two are automatic, so no role
// editor offers them; they are named for the screens that list what a person may do. The twelve
// staff jobs are what the role editor shows under Companies (amendments 10 and 11).
return [
    'company' => [
        'apply' => 'Apply as a Company',
        'update' => 'Change the Company\'s Address',
        'view' => 'View Companies',
        'review' => 'Approve and Reject Company Applications',
        'suspend' => 'Suspend and Reinstate Companies',
        'correct_type' => 'Correct a Company\'s Type',
        'transfer_type' => 'Move Companies from One Type to Another',
    ],
    'company_document' => [
        'view' => 'Open Company Papers',
    ],
    'company_type' => [
        'create' => 'Add Company Types',
        'update' => 'Rename and Reorder Company Types',
        'deactivate' => 'Deactivate and Reactivate Company Types',
    ],
    'document_type' => [
        'create' => 'Add Document Types',
        'update' => 'Rename, Reorder and Require Document Types',
        'deactivate' => 'Deactivate and Reactivate Document Types',
    ],
];
