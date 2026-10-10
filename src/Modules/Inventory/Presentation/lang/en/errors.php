<?php

declare(strict_types=1);

// Inventory's error messages, by error type (inventory.{key}), and the names of the fields they point
// at.
return [
    'not_enough_stock' => [
        'title' => 'Not enough stock',
        'detail' => 'Some items do not have enough stock, so nothing was held.',
    ],
    'already_held' => [
        'title' => 'Already held',
        'detail' => 'This order already holds stock.',
    ],
    'quantity_invalid' => [
        'title' => 'Check the quantity',
        'detail' => 'A quantity must be at least 1, and a count at least 0.',
    ],
    'stock_below_zero' => [
        'title' => 'Not that many in stock',
        'detail' => 'That would take the stock below 0.',
    ],
    'note_required' => [
        'title' => 'Add a note',
        'detail' => 'A correction needs a short note saying why.',
    ],
    'ship_more_than_held' => [
        'title' => 'Too many shipped',
        'detail' => 'That ships more of an item than the order holds.',
    ],
    'provider_owns_stock' => [
        'title' => 'Set by the provider',
        'detail' => "This store's stock comes from its provider and cannot be changed here.",
    ],
    'not_wired' => [
        'title' => 'Not available here',
        'detail' => 'Only a store wired to a provider has stock-dependent sizes; here every product already counts on its stock.',
    ],
    'threshold_invalid' => [
        'title' => 'Check the threshold',
        'detail' => 'A threshold must be 0 or more.',
    ],
    'store_off' => [
        'title' => 'This store is off',
        'detail' => 'Only someone who may switch stores on and off changes the stock of a store that is off.',
    ],
    'no_hold' => [
        'title' => 'Nothing held',
        'detail' => 'This order holds no stock.',
    ],
    'hold_not_editable' => [
        'title' => 'Cannot be changed',
        'detail' => 'Part of this order is already shipped or reduced in the provider.',
    ],

    'fields' => [],
];
