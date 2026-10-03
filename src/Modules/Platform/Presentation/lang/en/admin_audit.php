<?php

declare(strict_types=1);

// The audit log screen (frontend.md 3.5, E6).
return [
    'title' => 'Audit Log',
    'subtitle' => 'Who changed what, and when, kept forever and never edited.',

    'when' => 'When',
    'who' => 'Who',
    'what' => 'What',
    'subject' => 'On',
    'source' => 'How',
    'store' => 'Store',
    'ip' => 'From',
    'no_store' => 'The whole system',
    // A private file's entry, for somebody who may not see private files (b2b.md amendment 8(c)).
    'private_file' => 'a private file — which one, and what changed, is shown to those who may see private files',

    'source_web' => 'The Panel',
    'source_integration' => 'An Integration',
    'source_console' => 'A Console Command',
    'source_job' => 'A Background Job',
    'source_import' => 'An Import',

    'actor_staff' => 'Staff',
    'actor_customer' => 'Customer',
    'actor_guest' => 'Guest',
    'actor_integration' => 'Integration',
    'actor_system' => 'The System',
    'requested_by' => 'asked for by :who',

    'changes' => 'Changed',
    'personal' => 'changed',
    'from_to' => ':from to :to',
    'nothing_recorded' => 'Nothing was recorded alongside it.',

    'filters' => 'Filters',
    'dates' => 'Dates',
    'any_date' => 'Any Date',
    'preset_today' => 'Today',
    'preset_week' => 'Last 7 Days',
    'preset_month' => 'Last 30 Days',
    'preset_month_to_date' => 'Month to Date',
    'action_search' => 'Search actions',
    'action_none' => 'No actions match “:query”',
    'none_match_title' => 'No Entries Match Your Filters',
    'none_match' => 'Widen or clear the filters to see more.',
    'actor' => 'Who (ID)',
    'action' => 'What',
    'any' => 'Anything',
    'apply' => 'Apply Filters',
    'clear' => 'Clear Filters',
    'none' => 'Changes appear here as people and modules make them.',

    // Asked for by the Geist screens (frontend.md 1.10): empty states' titles, disabled
    // buttons' reasons and dialogs' own words.
    'none_title' => 'No Entries',
];
