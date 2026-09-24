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
    'apply' => 'Apply',
    'clear' => 'Clear',
    'none' => 'No customer matches that.',
    'total' => ':count in all',
    'previous' => 'Previous',
    'next' => 'Next',

    'name' => 'Name',
    'email' => 'Email',
    'phone' => 'Phone',
    'home_store' => 'Store',
    'registered' => 'Registered',
    'verified' => 'Confirmed',
    'email_verified' => 'Email confirmed',
    'phone_verified' => 'Phone confirmed',
    'not_verified' => 'Not confirmed yet',
    'no_phone' => 'No number',

    'account_type' => [
        'INDIVIDUAL' => 'Person',
        'COMPANY' => 'Company',
    ],
    'account_status' => [
        'ACTIVE' => 'Active',
        'BLOCKED' => 'Blocked',
    ],
    'deletion_pending' => 'Closing on :date',
    'anonymized' => 'Anonymized',

    // G2.
    'addresses' => 'Addresses',
    'no_addresses' => 'No address saved.',
    'communication_language' => 'We write to them in',
    'profile_is_theirs' => 'A customer\'s details are their own. Nothing here changes them.',
    'actions' => 'Actions',
    'reason' => 'Why',
    'reason_hint' => 'Kept with the change, for whoever asks about it later.',
    'block' => 'Block the account',
    'block_body' => 'They cannot sign in. Only the right password is told that the account is blocked, so a stranger guessing learns nothing.',
    'unblock' => 'Unblock the account',
    'unblock_body' => 'They can sign in again.',
    'start_deletion' => 'Close the account at their request',
    'start_deletion_body' => 'The same :count days the customer can start themselves: locked at once, anonymized on that date, and signing in before then cancels it.',
    'cancel_deletion' => 'Stop the closing',
    'cancel_deletion_body' => 'For somebody who cannot sign in to stop it themselves, which is the only other way.',
    'confirm' => 'Confirm',
    'cancel' => 'Cancel',

    'blocked' => 'The account is blocked.',
    'unblocked' => 'The account is active again.',
    'deletion_started' => 'The account is closing.',
    'deletion_cancelled' => 'The closing was stopped.',
];
