<?php

declare(strict_types=1);

// The names of Sync's queued jobs, as the failed jobs screen shows them.
return [
    'queue_hourly_reads' => "Queuing the hour's reads of the wired stores",
    'queue_nightly_reads' => "Queuing the night's reads of the wired stores",
    'read_store' => "Reading a store's prices and stock from its provider",
];
