<?php

declare(strict_types=1);

// The names of Ops' permissions (OpsPermissions): the jobs the role editor shows under Messages and
// reports, what everyone holds for their own inbox, and the system's nightly removal.
return [
    'message' => [
        'send_one' => 'Send messages to a customer',
        'send_many' => 'Send messages to many customers',
    ],
    'notice' => [
        'manage' => 'Manage offer notices',
    ],
    'newsletter' => [
        'manage' => 'Manage newsletter subscribers',
    ],
    'report' => [
        'view' => 'View and download reports',
        'daily' => 'Make and download daily reports',
    ],
    'inbox' => [
        'view_own' => 'Read my notifications',
    ],
    'panel_inbox' => [
        'view_own' => 'Read my notifications in the panel',
    ],
    'log' => [
        'prune' => 'Remove notifications and message records older than a year',
    ],
];
