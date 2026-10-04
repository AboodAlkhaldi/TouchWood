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
    'no_new_store' => 'A store is opened by console command, so it is created complete.',
    'no_stores' => 'Stores you are given access to appear here.',

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
];
