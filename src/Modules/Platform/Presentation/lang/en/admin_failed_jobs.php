<?php

declare(strict_types=1);

// The failed jobs screen (frontend.md 3.5, E7). The menu's name, the home line and the delete
// confirmation are the owner's words (2026-09-29).
return [
    'title' => 'Failed Jobs',
    'subtitle' => 'Background work that failed its last try, oldest first, waiting to be retried or deleted.',
    'none' => 'Background work that fails its last try waits here.',

    'job' => 'Job',
    'failed_at' => 'Failed',
    'tries' => 'Tries Allowed',
    'no_limit' => 'No limit',
    'set_by_worker' => 'Set by the worker',
    'queue' => 'Queue',
    'error' => 'Error',

    'retry' => 'Retry Job',
    'actions' => 'Actions',
    'delete_open' => 'Delete Job…',
    'copy_error' => 'Copy Error',
    'retried' => 'Job requeued',
    'confirm_delete' => 'The job will not run. This cannot be undone.',
    'deleted' => 'Job deleted',

    // Asked for by the Geist screens (frontend.md 1.10): empty states' titles, disabled
    // buttons' reasons and dialogs' own words.
    'none_title' => 'No Failed Jobs',
    'delete_title' => 'Delete Job',
];
