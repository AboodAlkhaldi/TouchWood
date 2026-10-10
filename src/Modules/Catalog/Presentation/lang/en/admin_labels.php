<?php

declare(strict_types=1);

// The labels screen (catalog.md §1.8, §4.4 S5). Geist's writing rules (frontend.md §1.10).
return [
    'title' => 'Labels',
    'subtitle' => 'One list for every store; each store attaches labels to its own products.',
    'add' => 'Add Label',
    'body' => 'A name of one or two words in each language, and a look named by its meaning.',
    'edit' => 'Edit…',
    'edit_title' => 'Edit Label',
    'save' => 'Save Label',
    'activate' => 'Activate Label',
    'deactivate' => 'Deactivate Label',
    'delete' => 'Delete Label…',
    'delete_title' => 'Delete Label',
    'delete_body' => ':name is deleted.',
    'empty' => [
        'title' => 'No Labels Yet',
        'body' => 'Add the labels stores may attach to products, such as New or Clearance.',
    ],
    'column' => [
        'label' => 'Label',
        'other' => 'Other Language',
        'meaning' => 'Meaning',
    ],
    'meaning' => [
        'neutral' => 'Neutral',
        'information' => 'Information',
        'healthy' => 'Healthy',
        'warning' => 'Warning',
        'error' => 'Error',
    ],
    'strength' => [
        'title' => 'Strength',
        'strong' => 'Strong',
        'subtle' => 'Subtle',
    ],
    'preview' => 'As shoppers see it:',
    'preview_empty' => 'Label',
    'name_helper' => 'One or two words, up to 30 characters.',
    // Said under a name as it is typed (frontend.md §1.7; Label::checkWords).
    'check' => [
        'words' => ':field is one or two words.',
    ],
    'position_helper' => 'Its place on a product\'s card, lowest first.',
    'reason' => [
        'in_use' => 'A store shows it on products: take it off them first.',
    ],
    'toast' => [
        'added' => 'Label added',
        'edited' => 'Label saved',
        'activated' => 'Label activated',
        'deactivated' => 'Label deactivated',
        'deleted' => 'Label deleted',
    ],
];
