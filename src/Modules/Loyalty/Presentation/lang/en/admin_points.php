<?php

declare(strict_types=1);

// Loyalty's staff screens (loyalty.md §4.1): Points › Customers, a customer's points, the change made
// by hand, Points › Redemptions, and the customer page's Points section.
return [
    'customers' => [
        'title' => 'Customers',
        'subtitle' => 'Who holds points in this store',
    ],
    'customer' => [
        'subtitle' => 'Points',
        'open_customer' => 'Open Customer',
    ],
    'redemptions' => [
        'title' => 'Redemptions',
        'subtitle' => 'Points used on orders in this store',
    ],
    'breadcrumb' => 'Points',

    'programme_off' => 'Points are off in this store: nobody earns or uses points. Balances stay, and still expire.',
    'deleted_customer' => 'Deleted customer',

    'search' => [
        'label' => 'Search customers',
        'placeholder' => 'Search by name, email or phone',
        'too_short' => 'Type at least 3 characters.',
        'too_many' => 'Too many customers match — type more of the name, email or phone.',
        'no_match' => 'No customers match ":text".',
        'clear' => 'Clear Search',
    ],

    'columns' => [
        'customer' => 'Customer',
        'points' => 'Points',
        'worth' => 'Worth',
        'expiring' => 'Expiring Next',
        'last_change' => 'Last Change',
        'date' => 'Date',
        'what' => 'What',
        'order' => 'Order',
        'by' => 'By',
        'reason' => 'Reason',
        'expires' => 'Expires',
        'discount' => 'Discount',
    ],
    'expiring_on' => ':points on :day',

    'empty' => [
        'holders_title' => 'No points yet',
        'holders_body' => 'When customers earn points in this store, they appear here.',
        'redemptions_title' => 'No redemptions yet',
        'redemptions_body' => 'When customers use points on an order here, it appears here.',
        'customer_title' => 'No points in this store',
        'customer_body' => 'This customer has not earned or used points here.',
    ],

    'card' => [
        'points' => 'Points',
        'worth' => 'Worth',
        'expiring' => 'Expiring Next',
        'expiring_line' => 'Expiring on :day: :points',
        'none_expiring' => 'No points are waiting to expire.',
        'change' => 'Change Points',
    ],
    'live' => 'Live Points',
    'history' => 'History',

    'kind' => [
        'EARNED' => 'Earned',
        'REDEEMED' => 'Used',
        'REDEMPTION_RETURNED' => 'Given back',
        'EARNING_REVERSED' => 'Taken back',
        'ADDED' => 'Added by hand',
        'DEDUCTED' => 'Removed by hand',
        'EXPIRED' => 'Expired',
    ],

    'dialog' => [
        'title' => 'Change Points',
        'description' => 'Add points to this customer\'s balance in :store, or remove some.',
        'add' => 'Add',
        'remove' => 'Remove',
        'mode' => 'Change',
        'points' => 'Points',
        'reason' => 'Reason',
        'reason_hint' => 'Kept in the customer\'s history and the audit log. The customer does not see it.',
        'balance_line' => 'Balance: :before → :after',
        'at_most' => 'At most the balance, :balance.',
        'nothing_to_remove' => 'There are no points to remove.',
        'mode_missing' => 'Choose Add or Remove.',
        'store_missing' => 'Open the customer\'s points in a store first.',
        'points_whole' => 'Points are a whole number, 1 or more.',
        'add_points' => 'Add Points',
        'remove_points' => 'Remove Points',
    ],

    'toast' => [
        'added' => 'Points added: :count.',
        'removed' => 'Points removed: :count.',
    ],

    'section' => [
        'title' => 'Points',
        'none_in_stores' => 'No points in your stores.',
        'view' => 'View Points',
        'give' => 'Give Points',
    ],
];
