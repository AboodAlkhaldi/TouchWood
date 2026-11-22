<?php

declare(strict_types=1);

// Wholesale Prices (pricing.md §4.3.3 P4).
return [
    'title' => 'Wholesale Prices',
    'subtitle' => 'Lower prices for buying in quantity, per size.',
    'add' => 'Add Wholesale Prices',
    'pick' => [
        'body' => 'Choose a size this store sells wholesale. Its bands come next.',
        'search' => 'Search sizes sold wholesale',
        'none' => 'No sizes match ":query"',
        'empty' => 'Every size sold wholesale here has bands.',
        'choose' => 'Choose :size',
    ],
    'column' => [
        'bands' => 'Bands',
        'minimum' => 'Minimum',
    ],
    'band_line' => 'From :from: :price',
    'mark' => [
        'kept' => 'Kept, Not Sold Wholesale',
        'no_retail' => 'No Retail Price',
        'no_retail_reason' => "The bands don't apply until the size has a price.",
        'minimum_changed' => 'Minimum Changed',
        'minimum_changed_reason' => 'The minimum is now :minimum; the first band starts at :first.',
    ],
    'menu' => [
        'edit' => 'Edit Wholesale Prices…',
        'remove' => 'Remove Wholesale Prices…',
    ],
    'empty' => [
        'title' => 'No wholesale prices',
        'body' => 'Add bands to sell a size for less in quantity.',
        'filtered_title' => 'No sizes match this search',
        'filtered_body' => 'Clear the search to see every size with bands.',
    ],
];
