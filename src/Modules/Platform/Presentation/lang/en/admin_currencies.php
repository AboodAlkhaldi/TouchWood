<?php

declare(strict_types=1);

// The currencies screen (frontend.md 3.5, E3).
return [
    'title' => 'Currencies',
    'subtitle' => 'What a store can charge in, kept by a Super Admin.',

    'code' => 'Code',
    'code_hint' => 'ISO 4217, three letters, and it never changes.',
    'name_ar' => 'Name in Arabic',
    'name_en' => 'Name in English',
    'abbreviation_ar' => 'Abbreviation in Arabic',
    'abbreviation_en' => 'Abbreviation in English',
    'abbreviation_hint' => 'Shown beside a price when there is no sign.',
    'sign' => 'Sign',
    'sign_hint' => 'Leave it empty to show the abbreviation instead; the preview draws it in the shop\'s font.',
    'sign_preview' => 'Preview',
    'exponent' => 'Decimal Places',
    'exponent_hint' => ':two for riyals and halalas; :zero for a currency with no smaller unit.',
    // The stores are named, not counted (platform.md §9.7): ":count stores" read "1 stores".
    'exponent_locked' => 'Used by :stores, so it is settled: every amount ever written in it means what this number says.',
    'in_use' => 'Used by :stores',
    'in_use_none' => 'No store yet',
    'store_off' => ':name (Off)',

    'add' => 'Add Currency',
    'edit' => 'Edit Currency',
    'save' => 'Save Currency',
    'cancel' => 'Cancel',
    'create' => 'Add Currency',
    'created' => 'Currency added',
    'saved' => 'Currency saved',
    'none' => 'Add one so a store can charge in it.',

    // Deleting a currency no store uses (platform.md §9.7), in Geist's Destructive Action Modal.
    'delete' => 'Delete Currency',
    'delete_title' => 'Delete Currency',
    'delete_confirm' => 'Delete Currency',
    'delete_body' => 'The currency :code (:name) will be deleted. No store charges in it.',
    'delete_irreversible' => 'Deleting :code cannot be undone.',
    'verification_label' => 'currency code',
    'deleted' => 'Currency deleted',

    // Asked for by the Geist screens (frontend.md 1.10): empty states' titles, disabled
    // buttons' reasons and dialogs' own words.
    'none_title' => 'No Currencies Yet',
];
