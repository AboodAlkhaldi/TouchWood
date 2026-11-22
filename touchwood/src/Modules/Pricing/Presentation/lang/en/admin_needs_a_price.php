<?php

declare(strict_types=1);

// Needs a Price (pricing.md §4.3.3 P5).
return [
    'title' => 'Needs a Price',
    'subtitle' => "Sizes switched on in this store with no price. Customers can't order them.",
    'wired' => "The provider's next read prices these.",
    'empty' => [
        'title' => 'Every size has a price',
        'body' => 'Sizes the store switches on without a price appear here.',
        'filtered_title' => 'No sizes match this search',
        'filtered_body' => 'Clear the search to see every size with no price.',
    ],
];
