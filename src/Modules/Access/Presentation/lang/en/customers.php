<?php

declare(strict_types=1);

// The customer screens in the panel (stage 2b step 4, frontend.md §3.7, G1 and G2).
return [
    'title' => 'Customers',
    'subtitle' => 'The people who shop in your stores.',

    'search' => 'Search',
    'search_hint' => 'A name, an email or a phone number.',
    'filters' => 'Filters',
    'type' => 'Kind',
    'status' => 'Status',
    'any' => 'Any',
    'apply' => 'Apply Filters',
    'clear' => 'Clear Filters',
    // Geist's Empty State: a filtered list that finds nobody suggests widening or clearing the
    // filters; a list with no customers at all says when they appear (shadcn rebuild).
    'none' => 'Widen or clear the filters to see more customers.',
    'empty_title' => 'No Customers Yet',
    'empty' => 'Customers appear here once they register in one of your stores.',
    'total' => ':count in all',
    'previous' => 'Previous',
    'next' => 'Next',

    'name' => 'Name',
    'email' => 'Email',
    'phone' => 'Phone',
    'home_store' => 'Store',
    'registered' => 'Registered',
    'verified' => 'Confirmed',
    'email_verified' => 'Email Confirmed',
    'phone_verified' => 'Phone Confirmed',
    // One badge per column, its word the state (Geist's Badge: never colour alone).
    'confirmed' => 'Confirmed',
    'not_confirmed' => 'Not Confirmed',
    'no_phone' => 'No number',

    'account_type' => [
        'INDIVIDUAL' => 'Person',
        'COMPANY' => 'Company',
    ],
    'account_status' => [
        'ACTIVE' => 'Active',
        'BLOCKED' => 'Blocked',
    ],
    // A badge is a word, two at most (Geist); the date is said beside it.
    'closing' => 'Closing',
    'closing_on' => 'Closing On',
    'anonymized' => 'Anonymized',

    // G2.
    'addresses' => 'Addresses',
    'no_addresses' => 'No address saved.',
    'communication_language' => 'Communication Language',
    'profile_is_theirs' => 'A customer\'s details are their own. Nothing here changes them.',
    'actions' => 'Actions',
    'reason' => 'Why',
    'reason_hint' => 'Kept with the change, for whoever asks about it later.',
    'block' => 'Block Account',
    'block_body' => 'They cannot sign in. Only the right password is told that the account is blocked, so a stranger guessing learns nothing.',
    'unblock' => 'Unblock Account',
    'unblock_body' => 'They can sign in again.',
    'start_deletion' => 'Close Account at Their Request',
    'start_deletion_body' => 'The same :count days the customer can start themselves: locked at once, anonymized on that date, and signing in before then cancels it.',
    'cancel_deletion' => 'Stop Account Closing',
    'cancel_deletion_body' => 'For somebody who cannot sign in to stop it themselves, which is the only other way.',
    'confirm' => 'Confirm',
    'cancel' => 'Cancel',

    'blocked' => 'Account blocked',
    'unblocked' => 'Account unblocked',
    'deletion_started' => 'Account closed',
    'deletion_cancelled' => 'Account closing stopped',

    // Asked for by the Geist screens (frontend.md 1.10): empty states' titles, disabled
    // buttons' reasons and dialogs' own words.
    'none_title' => 'No Customers Match Your Filters',
    'no_addresses_title' => 'No Addresses',
];
