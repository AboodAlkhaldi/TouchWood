<?php

declare(strict_types=1);

// The audit log screen (frontend.md 3.5, E6).
return [
    'title' => 'Audit log',
    'subtitle' => 'Who changed what, and when. Kept forever, and never edited.',

    'when' => 'When',
    'who' => 'Who',
    'what' => 'What',
    'subject' => 'On',
    'source' => 'How',
    'store' => 'Store',
    'ip' => 'From',
    'no_store' => 'The whole system',

    'source_web' => 'The panel',
    'source_integration' => 'An integration',
    'source_console' => 'A console command',
    'source_job' => 'A background job',
    'source_import' => 'An import',

    'actor_staff' => 'Staff',
    'actor_customer' => 'Customer',
    'actor_guest' => 'Guest',
    'actor_integration' => 'Integration',
    'actor_system' => 'The system',
    'requested_by' => 'asked for by :who',

    'changes' => 'Changed',
    'personal' => 'changed',
    'from_to' => ':from to :to',
    'nothing_recorded' => 'Nothing was recorded alongside it.',

    'filters' => 'Filters',
    'from' => 'From',
    'until' => 'Until',
    'actor' => 'Who (id)',
    'action' => 'What',
    'any' => 'Anything',
    'apply' => 'Apply',
    'clear' => 'Clear',
    'more' => 'Show more',
    'none' => 'Nothing here yet.',
];
