<?php

declare(strict_types=1);

// What Ops' queued work is called on the failed jobs screen (frontend.md E7).
return [
    'prune_ops_log' => 'Removing inbox entries, sent messages and handled events older than twelve months',
];
