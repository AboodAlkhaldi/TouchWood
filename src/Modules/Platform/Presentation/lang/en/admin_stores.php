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
    'tax_rate' => 'Tax rate',
    'tax_rate_hint' => 'A percentage. 15 is fifteen per cent.',
    'timezone' => 'Timezone',
    'position' => 'Position',
    'position_hint' => 'Where this store sits in the list a visitor chooses from.',

    'edit' => 'Edit',
    'save' => 'Save',
    'cancel' => 'Cancel',
    'saved' => 'The store was saved.',

    // Said on the screen, so nobody hunts for a button that was never there.
    'immutable' => 'The code, the country and the currency are fixed when the store is opened.',
    'no_new_store' => 'A store is opened by console command, so it is created complete.',
    'no_stores' => 'No store here is yours to see.',
];
