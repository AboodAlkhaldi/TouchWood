<?php

declare(strict_types=1);

// The SMS Countries page (ops.md §4.15 O10). Wording approved for now; the owner checks the whole site's wording later.
return [
    'title' => 'SMS Countries',
    'subtitle' => 'Which countries\' phones can get SMS codes, and who sends them.',
    'log_label' => 'Log Provider',
    'log_note' => 'Log writes codes to the server\'s log and sends nothing. A live system refuses it.',
    'log' => 'Log',
    'add' => 'Add Country',
    'columns' => [
        'country' => 'Country',
        'code' => 'Code',
        'calling_code' => 'Calling Code',
        'provider' => 'Provider',
    ],
    'empty_title' => 'No countries yet',
    'empty_body' => 'Without one, no phone number can be entered.',
    'menu' => [
        'provider' => 'Change Provider',
        'calling_code' => 'Change Calling Code',
        'remove' => 'Remove',
    ],
    'add_body' => 'Phones from this country can then get SMS codes.',
    'provider_title' => 'Change Provider of :country',
    'provider_body' => 'SMS codes to this country go through this provider.',
    'calling_code_title' => 'Change Calling Code of :country',
    'calling_code_body' => 'Phones are matched to this country by this code.',
    'save' => 'Save',
    'fields' => [
        'country' => 'Country',
        'country_rule' => 'Choose a country.',
        'search' => 'Search countries',
        'none' => 'No countries match ":query"',
        'listed' => 'Already on the list',
        'calling_code' => 'Calling Code',
        'calling_code_rule' => 'One to three digits, not starting with 0.',
        'provider' => 'Provider',
    ],
    'remove' => [
        'title' => 'Remove :country?',
        'body' => 'Phones from this country can\'t get codes. Staff with one need an admin to change it. A Super Admin with one enters a new number at sign-in. Customers with one can\'t order until they change it.',
        'keep' => 'Keep',
        'confirm' => 'Remove',
    ],
    'toast' => [
        'added' => 'Country added',
        'provider_changed' => 'Provider changed',
        'calling_code_changed' => 'Calling code changed',
        'removed' => 'Country removed',
    ],
];
