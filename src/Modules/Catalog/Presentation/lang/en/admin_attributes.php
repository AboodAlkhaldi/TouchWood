<?php

declare(strict_types=1);

// The attributes screens (catalog.md §1.7, §4.4 S3): one job, one word file.
// Geist's writing rules (frontend.md §1.10).
return [
    'title' => 'Attributes',
    'subtitle' => 'One shared library: what a product is made of, what it is filtered by, and what makes its variants.',
    'add' => 'Add Attribute',
    'add_body' => 'Its job decides what it does: details only, a filter, or making variants.',
    'edit' => 'Edit…',
    'edit_title' => 'Edit Attribute',
    'edit_body' => 'Every store shows the change at once.',
    'save' => 'Save Attribute',
    'activate' => 'Activate Attribute',
    'deactivate' => 'Deactivate Attribute',
    'delete' => 'Delete Attribute…',
    'delete_title' => 'Delete Attribute',
    'delete_body' => ':name is deleted, with its :count values.',
    'colour' => 'Colour',
    // The list's Colour column: a colour attribute is marked so (S3).
    'colour_yes' => 'Colour',
    'details' => 'Details',
    'empty' => [
        'title' => 'No Attributes Yet',
        'body' => 'Add the attributes products are described, filtered and varied by.',
    ],
    'column' => [
        'kind' => 'Job',
        'unit' => 'Unit',
        'values' => 'Values',
    ],
    'kind' => [
        'INFORMATIONAL' => 'Details Only',
        'FILTERABLE' => 'Filter',
        'VARIANT' => 'Makes Variants',
    ],
    'kind_helper' => [
        'INFORMATIONAL' => 'Each variant gives its own text or number; shoppers read it, and cannot filter by it.',
        'FILTERABLE' => 'A list of values set on a product; shoppers filter by them.',
        'VARIANT' => 'A list of values each variant picks one of; shoppers choose between them.',
    ],
    'field' => [
        'kind' => 'Job',
        'unit_ar' => 'Arabic Unit',
        'unit_en' => 'English Unit',
        'unit_helper' => 'Both, or neither: mm, kg.',
        'colour' => 'Colour',
        'colour_helper' => 'Its values carry a swatch, shown as the colour itself.',
    ],
    'reason' => [
        'locked' => 'Its job stays: it has values, or variants carry details of it.',
        'in_products' => 'It stays Makes Variants: products make their variants of it.',
        'in_use' => 'A product\'s variants or its filters use it.',
        'value_in_use' => 'A variant or a product\'s filters use it.',
    ],
    'value' => [
        'title' => 'Values',
        'add' => 'Add Value',
        'edit' => 'Edit…',
        'edit_title' => 'Edit Value',
        'body' => 'Two values of one attribute are never alike, ignoring letter case.',
        'save' => 'Save Value',
        'activate' => 'Activate Value',
        'deactivate' => 'Deactivate Value',
        'delete' => 'Delete Value…',
        'delete_title' => 'Delete Value',
        'delete_body' => ':name is deleted.',
        'swatch' => 'Swatch',
        'swatch_pick' => 'Pick a colour',
        'swatch_helper' => 'A hash and six hexadecimal digits, as the colour picker writes it.',
        'empty_title' => 'No Values Yet',
        'empty_body' => 'Add the values products and variants choose from.',
        'none_kind' => 'A details-only attribute has no values: each variant gives its own text or number.',
    ],
    'toast' => [
        'added' => 'Attribute added',
        'edited' => 'Attribute saved',
        'activated' => 'Attribute activated',
        'deactivated' => 'Attribute deactivated',
        'deleted' => 'Attribute deleted',
        'value_added' => 'Value added',
        'value_edited' => 'Value saved',
        'value_activated' => 'Value activated',
        'value_deactivated' => 'Value deactivated',
        'value_deleted' => 'Value deleted',
    ],
];
