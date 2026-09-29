<?php

declare(strict_types=1);

// The names of B2B's permissions (B2BPermissions). The company's own two are automatic, so no role
// editor offers them; they are named for the screens that list what a person may do. The eleven
// staff jobs are what the role editor shows under Companies (amendment 10).
return [
    'company' => [
        'apply' => 'Apply as a company',
        'update' => 'Change the company\'s address',
        'view' => 'View companies',
        'review' => 'Approve and reject company applications',
        'suspend' => 'Suspend and reinstate companies',
        'correct_type' => 'Correct a company\'s type',
    ],
    'company_document' => [
        'view' => 'Open company papers',
    ],
    'company_type' => [
        'create' => 'Add company types',
        'update' => 'Rename and reorder company types',
        'deactivate' => 'Deactivate and reactivate company types',
    ],
    'document_type' => [
        'create' => 'Add document types',
        'update' => 'Rename, reorder and require document types',
        'deactivate' => 'Deactivate and reactivate document types',
    ],
];
