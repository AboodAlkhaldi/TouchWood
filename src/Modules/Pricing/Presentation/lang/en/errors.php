<?php

declare(strict_types=1);

// Pricing's error messages, by error type (pricing.{key}), and the names of the fields they point at.
return [
    'price_not_positive' => [
        'title' => 'Check the price',
        'detail' => 'A price must be above 0. To give a piece away, make it a gift.',
    ],
    'price_too_precise' => [
        'title' => 'Too many decimals',
        'detail' => "The store's currency takes at most :decimals decimals. Prices are never rounded for you.",
    ],
    'percent_out_of_range' => [
        'title' => 'Check the percentage',
        'detail' => 'A percentage off must be above 0 and below 100.',
    ],
    'sale_not_below_retail' => [
        'title' => 'Not a sale',
        'detail' => 'A sale price must be below the retail price.',
    ],
    'window_invalid' => [
        'title' => 'Check the dates',
        'detail' => "The end must be after the start, and a running sale's or discount's end cannot be set before now.",
    ],
    'sale_not_found' => [
        'title' => 'Sale not found',
        'detail' => 'That sale does not exist.',
    ],
    'category_discount_not_found' => [
        'title' => 'Discount not found',
        'detail' => 'That category discount does not exist.',
    ],
    'not_changeable' => [
        'title' => 'Cannot be changed',
        'detail' => 'A running one can only have its end changed or be ended now, and an ended one is kept as it is.',
    ],
    'always_wins_overlap' => [
        'title' => 'Two would always win',
        'detail' => 'Another sale or discount that always wins already covers some of these sizes in these dates. Change the dates, or untick "always wins".',
    ],
    'bands_invalid' => [
        'title' => 'Check the quantity prices',
        'detail' => 'The first band starts at the wholesale minimum; each next band starts at a higher quantity and costs less.',
    ],
    'not_sold_wholesale' => [
        'title' => 'Not sold wholesale',
        'detail' => 'The store does not sell this size wholesale. Switch wholesale on for it first.',
    ],
    'category_not_usable' => [
        'title' => 'Category not available',
        'detail' => 'That category does not exist or is off.',
    ],
    'no_retail_price' => [
        'title' => 'No retail price',
        'detail' => 'This size has no retail price in the store, so there is nothing to take a percentage off.',
    ],
    'provider_owns_price' => [
        'title' => 'Set by the provider',
        'detail' => "This store's retail prices come from its provider and cannot be changed here.",
    ],
    'store_off' => [
        'title' => 'This store is off',
        'detail' => 'Only someone who may switch stores on and off changes the prices of a store that is off.',
    ],
    'duplicate_lines' => [
        'title' => 'Repeated lines',
        'detail' => 'Each size and way of buying may appear once.',
    ],
    'lines_without_price' => [
        'title' => 'No price',
        'detail' => 'Some items have no price in this store.',
    ],
    'amounts_invalid' => [
        'title' => 'Check the amounts',
        'detail' => 'The amounts do not fit these prices.',
    ],

    'fields' => [],
];
