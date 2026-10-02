<?php

declare(strict_types=1);

// The failed jobs screen (frontend.md 3.5, E7). The menu's name, the home line and the delete
// confirmation are the owner's words (2026-09-29).
return [
    'title' => 'Failed Jobs',
    'subtitle' => 'Work that ran in the background and failed its last try, oldest first. Each waits here until it is retried or deleted.',
    'none' => 'Nothing has failed.',

    'job' => 'Job',
    'failed_at' => 'Failed',
    'tries' => 'Tries Allowed',
    'no_limit' => 'No limit',
    'set_by_worker' => 'Set by the worker',
    'more' => 'Show More',
    'queue' => 'Queue',
    'error' => 'Error',

    'retry' => 'Retry Job',
    'retried' => 'Job retried',
    'delete' => 'Delete Job',
    'confirm_delete' => 'The job will not run. This cannot be undone.',
    'deleted' => 'Job deleted',
    'cancel' => 'Cancel',

    // Asked for by the Geist screens (frontend.md 1.10): empty states' titles, disabled
    // buttons' reasons and dialogs' own words.
    'none_title' => 'No Failed Jobs',
    'delete_title' => 'Delete Job',
];
