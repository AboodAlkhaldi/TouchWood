<?php

declare(strict_types=1);

// The names of Platform's permissions in the role editor (PlatformPermissions).
return [
    'store' => [
        'create' => 'Create Stores',
        'update' => 'Edit Stores',
        'view' => 'View Stores',
    ],
    'currency' => [
        'create' => 'Create Currencies',
        'update' => 'Edit Currencies',
    ],
    'settings' => [
        'view' => 'View Settings',
        'update' => 'Change Settings',
    ],
    'media' => [
        'upload' => 'Upload Files',
        'update' => 'Edit File Descriptions',
        'delete' => 'Delete Files',
        'private' => [
            'view' => 'View Private Files',
        ],
        'variants' => [
            'generate' => 'Generate Image Sizes',
        ],
    ],
    'audit' => [
        'view' => 'View the Audit Log',
    ],
    'jobs' => [
        'manage' => 'See, Retry and Delete Failed Jobs',
    ],
];
