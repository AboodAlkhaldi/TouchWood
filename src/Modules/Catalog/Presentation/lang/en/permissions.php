<?php

declare(strict_types=1);

// The names of Catalog's permissions (CatalogPermissions): the seventeen jobs the role editor shows
// under Catalog, and the three reserved to a Super Admin and the system, named for the screens that
// list what a person may do.
return [
    'product' => [
        'create' => 'Add products',
        'update' => 'Edit products',
        'publish' => 'Make products ready',
        'archive' => 'Archive and restore products',
        'view' => 'View products',
    ],
    'variant' => [
        'correct_code' => 'Correct a product code',
    ],
    'listing' => [
        'choose' => 'Choose what the store sells',
        'selling' => 'Set selling modes and quantity limits',
        'unavailable' => 'Mark products "Not available now"',
        'labels' => 'Attach labels to products',
        'rebuild' => 'Rebuild the product listing (the system)',
    ],
    'category' => [
        'rank' => 'Order the store\'s categories',
        'manage' => 'Manage categories',
    ],
    'brand' => [
        'manage' => 'Manage brands',
    ],
    'attribute' => [
        'manage' => 'Manage attributes, values and colours',
    ],
    'label' => [
        'manage' => 'Manage labels',
    ],
    'warranty' => [
        'manage' => 'Manage warranties',
    ],
    'search_word' => [
        'manage' => 'Manage search word pairs',
    ],
    'import' => [
        'run' => 'Import products from a file',
    ],
    'search_log' => [
        'prune' => 'Remove old search log entries (the system)',
    ],
];
