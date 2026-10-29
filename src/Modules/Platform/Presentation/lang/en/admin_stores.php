<?php

declare(strict_types=1);

// The stores screen in the admin panel (frontend.md 3.5, E1 and E2).
return [
    'title' => 'Stores',
    'subtitle' => 'One card per store: what it is called, what it charges in, and where it is.',

    'name' => 'Name',
    'name_ar' => 'Name in Arabic',
    'name_en' => 'Name in English',
    'code' => 'Code',
    'country' => 'Country',
    'currency' => 'Currency',
    'tax_rate' => 'Tax Rate',
    'tax_rate_hint' => 'A percentage: 15 is fifteen per cent.',
    'timezone' => 'Time Zone',
    'position' => 'Position',
    'position_hint' => 'Where this store sits in the list a visitor chooses from.',

    'edit' => 'Edit Store',
    'save' => 'Save Store',
    'cancel' => 'Cancel',
    'saved' => 'Store saved',

    // Said on the screen, so nobody hunts for a button that was never there.
    'immutable' => 'The code, the country and the currency are fixed when the store is opened.',
    'no_stores' => 'Stores you are given access to appear here.',

    // Add Store (platform.md §9.7 #3, #4; owner, 2026-10-06): a Super Admin, everything at once.
    'add' => 'Add Store',
    'create' => 'Add Store',
    'created' => ':name added. It is switched off: prepare it, then turn it on.',
    'starts_off' => 'The store is added switched off: prepare it, then turn it on.',
    'code_hint' => '2 to 8 lowercase letters, in the shop\'s address (/sa/en). It never changes.',
    'country_search' => 'Search countries',
    'country_none' => 'No countries match “:query”',
    'countries_ours' => 'Our Countries',
    'countries_all' => 'All Countries',
    'currency_hint' => 'Only currencies no store uses: each currency serves one store. It never changes.',
    'currency_new_option' => 'New Currency…',
    'currency_new' => 'New Currency',
    'currency_new_hint' => 'Its names and sign can be changed later on the Currencies page.',
    'currency_none_free' => 'Every currency already serves a store, so add this store\'s own.',

    // Asked for by the Geist screens (frontend.md 1.10): empty states' titles, disabled
    // buttons' reasons and dialogs' own words.
    'none_title' => 'No Stores to Show',

    // The on/off switch (owner, 2026-10-01): a Super Admin only.
    'status' => 'Status',
    'on' => 'On',
    'off' => 'Off',
    'base' => 'Base Store',
    'base_hint' => 'The base store is always on.',
    'off_hint' => 'Visitors and staff can\'t see this store until it\'s turned on.',
    'turn_on' => 'Turn Store On',
    'turn_off' => 'Turn Store Off',
    'turn_off_open' => 'Turn Store Off…',
    'timezone_search' => 'Search time zones',
    'timezone_none' => 'No time zones match “:query”',
    // A dialog's description is a statement, never a question (Geist's Modal).
    'turn_off_confirm' => 'The :name shop closes and the store disappears from every store list. Nothing is deleted.',
    'turned_on' => 'Store turned on',
    'turned_off' => 'Store turned off',

    // A store's ship-to countries (platform.md §4.3, E1 addition).
    'ships_to' => [
        'title' => 'Ships To',
        'own' => 'Own',
        'own_hint' => 'A store always ships to its own country.',
        'add' => 'Add Country',
        'add_title' => 'Add a Country to :store',
        'add_body' => 'Customers there can then order from this store, priced by its carriers.',
        'pick_first' => 'Choose a country first.',
        'search' => 'Search countries',
        'no_match' => 'No countries match.',
        'shipped_elsewhere' => 'Ships from ":store"',
        'no_places' => 'No place list yet: add its places first',
        'none_left' => 'Every country on the address list is already shipped to.',
        'places_link' => 'Add places for :country',
        'remove' => 'Remove',
        'remove_title' => 'Remove :country',
        'remove_body' => '":store" stops shipping to ":country". Saved addresses there stay. Until another store ships there, orders to ":country" are handled by staff: no carrier, the fee confirmed later, no online payment.',
        'added' => 'Country added',
        'removed' => 'Country removed',
        'open_carriers' => 'Open the carriers of :country',
    ],
];
