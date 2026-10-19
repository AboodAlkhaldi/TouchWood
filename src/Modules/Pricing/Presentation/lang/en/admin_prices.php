<?php

declare(strict_types=1);

// Prices (pricing.md §4.3.3 P1) and its size drawer (Q3).
return [
    'title' => 'Prices',
    'subtitle' => 'Retail prices for each size, and what customers pay today.',
    'filters' => [
        'title' => 'Filters',
        'search' => 'Search sizes',
        'show' => 'Show',
        'ALL' => 'All',
        'NO_PRICE' => 'No Price',
        'ON_SALE_NOW' => 'On Offer Now',
        'HAS_WHOLESALE' => 'Has Wholesale Prices',
    ],
    'empty' => [
        'title' => 'No sizes yet',
        'body' => 'Sizes appear here once the store switches them on.',
        'filtered_title' => 'No sizes match these filters',
        'filtered_body' => 'Clear the filters to see every size.',
    ],
    'drawer' => [
        'retail' => 'Retail',
        'today' => 'Today',
        'one_piece' => 'One piece',
        'wholesale_line' => [
            'zero' => 'Wholesale, :count pieces',
            'one' => 'Wholesale, :count piece',
            'two' => 'Wholesale, :count pieces',
            'few' => 'Wholesale, :count pieces',
            'many' => 'Wholesale, :count pieces',
            'other' => 'Wholesale, :count pieces',
        ],
        'not_sold_retail' => 'Not sold one piece at a time here.',
        'why' => [
            'BASE' => 'The retail price',
            'SALE' => 'An offer',
            'CATEGORY' => 'A category discount',
            'QUANTITY' => 'A wholesale band',
        ],
        'reaches' => 'What Reaches It',
        'nothing' => 'Nothing reaches this size now.',
        'offer' => 'Offer: :off',
        'discount' => 'Category discount: :off',
        'bands' => 'Wholesale bands',
        'band_line' => 'From :from: :price',
        'wins_now' => 'Wins now',
        'skipped' => [
            'zero_or_below' => 'Skipped: would be 0 or below.',
            'past_ceiling' => 'Skipped: past the ceiling in this wired store.',
        ],
        'open_offer' => 'Open Offer',
        'open_discount' => 'Open Discount',
        'minimum_changed' => 'The minimum is now :minimum; the first band starts at :first.',
    ],
];
