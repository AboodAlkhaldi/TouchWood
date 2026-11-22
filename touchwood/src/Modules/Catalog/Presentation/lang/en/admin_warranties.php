<?php

declare(strict_types=1);

// The warranties screen (catalog.md §1.9, §4.4 S6). Geist's writing rules (frontend.md §1.10).
return [
    'title' => 'Warranties',
    'subtitle' => 'The warranties a product may carry, one at most, the same in every store.',
    'add' => 'Add Warranty',
    'body' => 'Its name, its period and its terms, in both languages.',
    'edit' => 'Edit…',
    'edit_title' => 'Edit Warranty',
    'save' => 'Save Warranty',
    'activate' => 'Activate Warranty',
    'deactivate' => 'Deactivate Warranty',
    'delete' => 'Delete Warranty…',
    'delete_title' => 'Delete Warranty',
    'delete_body' => ':name is deleted.',
    'lifetime' => 'Lifetime',
    // One form per plural category the page's language has (Intl.PluralRules, CLDR); English uses two.
    'months' => [
        'zero' => ':count months',
        'one' => ':count month',
        'two' => ':count months',
        'few' => ':count months',
        'many' => ':count months',
        'other' => ':count months',
    ],
    'empty' => [
        'title' => 'No Warranties Yet',
        'body' => 'Add the warranties products may carry.',
    ],
    'column' => [
        'period' => 'Period',
    ],
    'field' => [
        'period' => 'Months',
        'period_helper' => 'From 1 to 600.',
        'terms_ar' => 'Arabic Terms',
        'terms_en' => 'English Terms',
    ],
    'reason' => [
        'in_use' => 'Products carry it: give them another first.',
    ],
    'toast' => [
        'added' => 'Warranty added',
        'edited' => 'Warranty saved',
        'activated' => 'Warranty activated',
        'deactivated' => 'Warranty deactivated',
        'deleted' => 'Warranty deleted',
    ],
];
