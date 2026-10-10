<?php

declare(strict_types=1);

// The brands screen (catalog.md §1.6, §4.4 S1). Geist's writing rules (frontend.md §1.10).
return [
    'title' => 'Brands',
    'subtitle' => 'Every brand the products carry, shared by every store.',
    'add' => 'Add Brand',
    'add_body' => 'It gets the next free number, which never changes while it exists.',
    'edit' => 'Edit…',
    'edit_title' => 'Edit Brand',
    'edit_body' => 'Every store shows the change at once.',
    'save' => 'Save Brand',
    'make_default' => 'Make Default',
    'activate' => 'Activate Brand',
    'deactivate' => 'Deactivate Brand…',
    'delete' => 'Delete Brand…',
    'delete_title' => 'Delete Brand',
    'delete_body' => ':name is deleted, and its number and addresses are free again.',
    'default' => 'Default',
    'empty' => [
        'title' => 'No Brands Yet',
        'body' => 'Add the brands your products carry; a new product starts on the default one.',
    ],
    'column' => [
        'number' => 'No.',
        'agency' => 'Agency',
        'listings' => 'Shown',
    ],
    'agency' => [
        'HOUSE' => 'House Brand',
        'EXCLUSIVE_AGENT' => 'Exclusive Agent',
        'DISTRIBUTOR' => 'Distributor',
    ],
    'listings' => [
        'everywhere' => 'Everywhere',
        'secondary' => 'Through Its Category',
    ],
    'reason' => [
        'default' => 'The default brand stays: make another the default first.',
        'in_use' => 'Products carry it: move them to another brand first.',
    ],
    'field' => [
        'agency' => 'Agency',
        'listings' => 'Show in Search and Shop-Wide Lists',
        'listings_helper' => 'Off makes it a secondary brand, reached only through its own category.',
        'country' => 'Country of Origin',
        'logo' => 'Logo',
        'description_ar' => 'Arabic Description',
        'description_en' => 'English Description',
    ],
    'country' => [
        'search' => 'Search countries',
        'none' => 'No countries match “:query”',
        'clear' => 'Clear Country',
    ],
    'toast' => [
        'added' => 'Brand added',
        'edited' => 'Brand saved',
        'default' => 'Default brand changed',
        'activated' => 'Brand activated',
        'deactivated' => 'Brand deactivated',
        'deleted' => 'Brand deleted',
    ],
];
