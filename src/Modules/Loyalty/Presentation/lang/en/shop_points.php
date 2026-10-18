<?php

declare(strict_types=1);

// The customer's own points, a page of their account in the shop (loyalty.md §4.1 L7).
return [
    'title' => 'My Points',
    'subtitle' => 'Your points in this store',
    'balance' => 'Points',
    'worth' => 'Worth :amount',
    'expiring_line' => 'Expiring on :day: :points',
    'none_expiring' => 'No points are waiting to expire.',
    // Worded so it reads right for any number, 1 included: the screens' words have no plurals.
    'how_it_works' => 'Every :unit you spend adds :earn to your points. Every :off of your points take :unit off an order.',
    'paused' => 'Points are paused in this store. You cannot earn or use points for now; your points stay until they expire.',
    'live' => 'Live Points',
    'history' => 'History',
    'columns' => [
        'date' => 'Date',
        'what' => 'What',
        'points' => 'Points',
        'expires' => 'Expires',
    ],
    'kind' => [
        'EARNED' => 'Earned on order :order',
        'REDEEMED' => 'Used on order :order',
        'REDEMPTION_RETURNED' => 'Given back — order :order',
        'EARNING_REVERSED' => 'Taken back — order :order returned',
        'ADDED' => 'Added by the store',
        'DEDUCTED' => 'Removed by the store',
        'EXPIRED' => 'Expired',
    ],
    'empty_title' => 'No points yet',
    'empty_body' => 'You earn points on every delivered order.',
];
