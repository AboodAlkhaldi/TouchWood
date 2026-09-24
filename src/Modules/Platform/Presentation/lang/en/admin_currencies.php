<?php

declare(strict_types=1);

// The currencies screen (frontend.md 3.5, E3).
return [
    'title' => 'Currencies',
    'subtitle' => 'What a store can charge in. A Super Admin keeps this list.',

    'code' => 'Code',
    'code_hint' => 'ISO 4217, three letters. It never changes.',
    'name_ar' => 'Name in Arabic',
    'name_en' => 'Name in English',
    'abbreviation_ar' => 'Abbreviation in Arabic',
    'abbreviation_en' => 'Abbreviation in English',
    'abbreviation_hint' => 'Shown beside a price when there is no sign.',
    'sign' => 'Sign',
    'sign_hint' => 'Drawn below in the font the shop uses, so you can see it before you save it. Leave it empty to show the abbreviation instead.',
    'sign_preview' => 'As a price will show it',
    'exponent' => 'Decimal places',
    'exponent_hint' => '2 for riyals and halalas; 0 for a currency with no smaller unit.',
    'exponent_locked' => 'Settled: :count stores already charge in this currency, and every amount ever written in it means what this number says.',
    'in_use' => ':count stores',
    'in_use_none' => 'No store yet',

    'add' => 'Add a currency',
    'edit' => 'Edit',
    'save' => 'Save',
    'cancel' => 'Cancel',
    'create' => 'Add it',
    'created' => 'The currency was added.',
    'saved' => 'The currency was saved.',
    'none' => 'No currency yet.',
];
