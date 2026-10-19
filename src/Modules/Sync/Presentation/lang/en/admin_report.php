<?php

declare(strict_types=1);

// The Sync Report and a read's page (sync.md §4.1 Y1, Y2; §4.1.1). Plurals in the six forms the page picks
// from (zero, one, two, few, many, other), as every module's screens write them.
return [
    'title' => 'Sync Report',
    'subtitle' => 'What the provider\'s reads found, and every read of this store.',

    'sync_now' => 'Sync Now',
    'wire_store' => 'Wire Store',

    'providers' => [
        'ODOO' => 'Odoo',
    ],

    // The status line (SyncStatus).
    'status' => [
        'states' => [
            'WORKING' => 'Working',
            'FAILING' => 'Failing',
            'NOT_WIRED' => 'Not Wired',
        ],
        'last_read' => 'Last read :time',
        'last_full_read' => 'Last full read :time',
        'never_read' => 'Not read yet',
        'running' => 'A read is running, started :time.',
        'waiting' => 'A read is waiting, asked :time.',
        'provider_said' => 'The provider said:',
        'press_sync_now' => 'Press Sync Now once the provider is back.',
        'not_wired' => 'This store is not wired to a provider.',
    ],

    // Sync's own reasons a read failed, kept as codes (Q31).
    'failures' => [
        'STOPPED_REWIRED' => 'Stopped: the store was unwired or wired again.',
        'WORKER_GONE' => 'Stopped: its worker went away.',
        'UNWIRED' => 'The store was unwired.',
        'SUPERSEDED' => 'Superseded: a read of the same kind is waiting.',
        'JOB_FAILED' => 'Failed: see the failed jobs screen.',
    ],

    'tabs' => [
        'problems' => 'Problems',
        'reads' => 'Reads',
    ],

    'showing' => 'Showing :shown of :total',
    'show_more' => 'Show More',

    // The report's kinds, as badges (§4.1.1).
    'kinds' => [
        'all' => 'All Kinds',
        'UNKNOWN_CODE' => 'Unknown Code',
        'NOT_IN_PROVIDER' => 'Not at Provider',
        'ARCHIVED_SKIPPED' => 'Archived',
        'OFF_OR_DRAFT_PRICED' => 'Off or Draft',
        'SALE_STOPPED' => 'Offer Stopped',
        'BAD_VALUE' => 'Bad Value',
        'READ_FAILED' => 'Read Failed',
        'DISCOUNT_SKIPPED' => 'Discount Skipped',
    ],
    'kind_count' => ':kind (:count)',

    'problems' => [
        'columns' => [
            'kind' => 'Kind',
            'what' => 'What',
            'detail' => 'Detail',
            'first_seen' => 'First Seen',
            'last_seen' => 'Last Seen',
            'actions' => 'Actions',
        ],
        'size_gone' => 'A size no longer here',
        'details' => [
            'sent' => 'Sent: price :price, stock :stock',
            'nothing_sent' => 'nothing',
            'not_at_provider' => 'The provider has no such code.',
            'archived' => 'Archived here; the provider still sends it.',
            'off_or_draft' => 'Sent at :price. Make the product ready and switch the size on to sell it at this price.',
            'offer_removed' => 'Offer removed',
            'outcomes' => [
                'left' => 'The size left the offer.',
                'ended' => 'The offer ended.',
                'removed' => 'The scheduled offer was removed.',
            ],
            'bad_price' => 'Price sent: :value. The size keeps its price.',
            'bad_stock' => 'Stock sent: :value. Not a whole number: skipped.',
            'below_zero' => 'Stock sent: :value. Kept as 0.',
            'twice' => 'The code came twice in one read: both skipped.',
            'read_failed' => 'The read failed.',
            'discount_skipped' => 'Past the ceiling at the provider\'s price.',
        ],
        'menu' => [
            'retry' => 'Retry Read',
            'open_offer' => 'Open Offer',
            'open_product' => 'Open Product',
            'dismiss' => 'Dismiss Entry…',
        ],
        'dismiss' => [
            'title' => 'Dismiss Entry',
            'body' => 'It leaves the report, and comes back if a read finds it again.',
            'confirm' => 'Dismiss Entry',
        ],
        'empty' => [
            'title' => 'No problems',
            'body' => 'The provider\'s last reads found nothing to look at.',
        ],
    ],

    // Why a control is drawn disabled (SyncActionsForReader).
    'reasons' => [
        'now_waiting' => 'A read is already waiting.',
        'size_gone' => 'This size no longer exists. Dismiss the entry instead.',
        'read_failed_stays' => 'Closes by itself when a read works.',
    ],

    // Reads, their kinds and states (§4.1.1).
    'read_kinds' => [
        'NOTIFIED' => 'Notice',
        'HOURLY' => 'Hourly',
        'NIGHTLY' => 'Nightly',
        'NOW' => 'Now',
        'CODE' => 'One Code',
    ],
    'read_states' => [
        'QUEUED' => 'Waiting',
        'RUNNING' => 'Running',
        'DONE' => 'Done',
        'FAILED' => 'Failed',
    ],
    'the_system' => 'The System',
    'reads' => [
        'columns' => [
            'kind' => 'Kind',
            'state' => 'State',
            'asked_by' => 'Asked By',
            'started' => 'Started',
            'took' => 'Took',
            'prices' => 'Prices',
            'stock' => 'Stock',
            'skipped' => 'Skipped',
            'codes' => 'Codes',
        ],
        'took' => ':minutes min :seconds s',
        'took_seconds' => ':seconds s',
        'none' => '—',
        'empty' => [
            'title' => 'No reads yet',
            'body' => 'Reads appear here once the store is wired.',
        ],
    ],

    // A read's page (Y2).
    'read' => [
        'title' => 'Read of :date',
        'header' => [
            'kind' => 'Kind',
            'state' => 'State',
            'asked_by' => 'Asked By',
            'queued' => 'Queued',
            'started' => 'Started',
            'finished' => 'Finished',
            'took' => 'Took',
            'counts' => 'Counts',
            'reason' => 'Reason',
        ],
        'counts_parts' => [
            'prices' => 'Prices changed',
            'stock' => 'Stock changed',
            'skipped' => 'Skipped',
        ],
        'sections' => [
            'PRICES' => 'Prices Changed',
            'STOCK' => 'Stock Changed',
            'REPORTED' => 'Reported',
            'CODES' => 'Codes Asked',
        ],
        'columns' => [
            'product' => 'Product',
            'size' => 'Size',
            'code' => 'Code',
            'from_to' => 'From → To',
            'to' => 'to',
        ],
        'empty' => 'Nothing in this section.',
    ],

    'toast' => [
        'read_queued' => 'Read queued',
        'entry_dismissed' => 'Entry dismissed',
    ],
];
