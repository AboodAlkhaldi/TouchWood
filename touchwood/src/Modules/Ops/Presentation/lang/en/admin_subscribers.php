<?php

declare(strict_types=1);

// The Subscribers page (ops.md §4.15 O7). Wording approved for now; the owner checks the whole site's wording later.
return [
    'title' => 'Subscribers',
    'subtitle' => 'Who gets the newsletter. Newsletters are written and sent in Brevo.',
    'empty' => 'No subscribers yet.',
    'columns' => [
        'email' => 'Email',
        'language' => 'Language',
        'kind' => 'Kind',
        'state' => 'State',
        'joined' => 'Joined',
        'confirmed' => 'Confirmed',
        'actions' => 'Actions',
    ],
    'language' => [
        'ar' => 'Arabic',
        'en' => 'English',
    ],
    'kind' => [
        'GUEST' => 'Guest',
        'CUSTOMER' => 'Customer',
    ],
    'state' => [
        'confirmed' => 'Confirmed',
        'waiting' => 'Waiting for Confirmation',
    ],
    'hidden' => 'Hidden',
    'hidden_why' => 'You may not view this store\'s customers.',
    'remove' => [
        'menu' => 'Remove',
        'title' => 'Remove :email from the newsletter?',
        'body' => 'They stop getting the newsletter.',
        'keep' => 'Keep',
        'confirm' => 'Remove',
        'toast' => 'Removed from the newsletter.',
    ],
];
