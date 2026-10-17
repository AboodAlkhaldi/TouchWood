<?php

declare(strict_types=1);

// The names of Sync's permissions (SyncPermissions): the two jobs the role editor shows under Sync,
// and the Super Admin's and the system's.
return [
    'connection' => [
        'manage' => 'Wire Stores to a Provider',
    ],
    'report' => [
        'view' => 'View the Sync Report',
    ],
    'read' => [
        'run' => 'Run Sync Reads',
        'scheduled' => 'Read Providers on Schedule',
    ],
    'log' => [
        'prune' => 'Delete the Old Sync Log',
    ],
];
