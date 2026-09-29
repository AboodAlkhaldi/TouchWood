<?php

declare(strict_types=1);

// The names of Platform's permissions in the role editor (PlatformPermissions).
return [
    'store' => [
        'create' => 'Create stores',
        'update' => 'Edit stores',
        'view' => 'View stores',
    ],
    'currency' => [
        'create' => 'Create currencies',
        'update' => 'Edit currencies',
    ],
    'settings' => [
        'view' => 'View settings',
        'update' => 'Change settings',
    ],
    'media' => [
        'upload' => 'Upload files',
        'update' => 'Edit file descriptions',
        'delete' => 'Delete files',
        'private' => [
            'view' => 'View private files',
        ],
        'variants' => [
            'generate' => 'Generate image sizes',
        ],
    ],
    'audit' => [
        'view' => 'View the audit log',
    ],
    'jobs' => [
        'manage' => 'See, retry and delete failed jobs',
    ],
];
