<?php

declare(strict_types=1);

// The names of what Sync audits (sync.md §3), shown in the audit log.
return [
    'connection.changed' => 'Provider connection changed',
    'connection.unwired' => 'Store unwired from its provider',
    'connection.wired' => 'Store wired to a provider',
    'entry.dismissed' => 'Sync report entry dismissed',
    'read.done' => 'Store read from its provider',
    'read.failed' => 'Reading the provider failed',
];
