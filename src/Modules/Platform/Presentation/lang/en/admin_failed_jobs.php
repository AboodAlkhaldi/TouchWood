<?php

declare(strict_types=1);

// The failed jobs screen (frontend.md 3.5, E7). The menu's name, the home line and the delete
// confirmation are the owner's words (2026-09-29).
return [
    'title' => 'Failed jobs',
    'subtitle' => 'Work that ran in the background and failed its last try, oldest first. Each waits here until it is retried or deleted.',
    'none' => 'Nothing has failed.',

    'job' => 'Job',
    'failed_at' => 'Failed',
    'tries' => 'Tries allowed',
    'no_limit' => 'No limit',
    'queue' => 'Queue',
    'error' => 'Error',

    'retry' => 'Retry',
    'retried' => 'The job is back on its queue.',
    'delete' => 'Delete',
    'confirm_delete' => 'Delete this job for good? It will not run.',
    'deleted' => 'The job was deleted.',
    'cancel' => 'Cancel',
];
