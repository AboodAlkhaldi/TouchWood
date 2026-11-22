<?php

declare(strict_types=1);

// The search words screen (catalog.md §1.11, §4.4 S7). Geist's writing rules (frontend.md §1.10).
return [
    'title' => 'Search Words',
    'subtitle' => 'Word pairs search treats as one, in every store and both languages.',
    'add' => 'Add Word Pair',
    'add_body' => 'A search for either word finds what the other finds.',
    'add_from' => 'Add Word Pair…',
    'delete' => 'Delete',
    'delete_title' => 'Delete Word Pair',
    'delete_body' => '“:one” and “:other” are searched apart again.',
    'pairs' => [
        'title' => 'Word Pairs',
        'column' => 'Pair',
        'means' => 'searched as one with',
        'empty_title' => 'No Word Pairs Yet',
        'empty_body' => 'Pair a word shoppers type with one the products use, such as مفصلة and hinge.',
    ],
    'searches' => [
        'title' => 'Searches That Found Nothing',
        'body' => 'Submitted searches of the last 12 months, the most searched first; no shopper is recorded.',
        'empty_title' => 'Nothing Missed',
        'empty_body' => 'Every search in the last 12 months found something.',
        'column' => [
            'words' => 'Words',
            'store' => 'Store',
            'language' => 'Language',
            'times' => 'Times',
            'last' => 'Last Searched',
        ],
    ],
    'field' => [
        'word_a' => 'Word',
        'word_b' => 'Searched As One With',
        // The second word named inside a sentence that says what is wrong with it (frontend.md §1.7).
        'word_b_subject' => 'The word searched as one with it',
        'word_helper' => 'Up to 50 characters each.',
    ],
    'toast' => [
        'added' => 'Word pair added',
        'deleted' => 'Word pair deleted',
    ],
];
