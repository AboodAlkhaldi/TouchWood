<?php

declare(strict_types=1);

// Offers (pricing.md §4.3.3 P2) - "Offer" is the panel's word for a sale (§9.4).
return [
    'title' => 'Offers',
    'subtitle' => 'Lower prices for chosen sizes, for a time.',
    'filters' => [
        'title' => 'Filters',
        'state' => 'State',
        'ALL' => 'All',
        'RUNNING' => 'Running',
        'SCHEDULED' => 'Scheduled',
        'ENDED' => 'Ended',
        'product' => 'Product',
        'any_product' => 'Any Product',
        'product_search' => 'Search products with offers',
        'product_none' => 'No products match ":query"',
        'sort' => 'Sort',
        'BY_STATE' => 'By State',
        'NEWEST' => 'Newest Added',
    ],
    'one_offer' => 'Showing the offer a link named.',
    'show_all' => 'Show All Offers',
    'column' => [
        'sizes' => 'Sizes',
    ],
    'empty' => [
        'title' => 'No offers',
        'body' => 'Add an offer to lower prices for a time.',
        'filtered_title' => 'No offers match these filters',
        'filtered_body' => 'Clear the filters to see every offer.',
    ],
];
