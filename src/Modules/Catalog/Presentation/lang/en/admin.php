<?php

declare(strict_types=1);

// What Catalog's panel screens share (catalog.md §4.4): states, columns, fields, the description's
// marks, a photo's upload, dragging into order, and deactivating with each product's fate. Geist's
// writing rules (frontend.md §1.10).
return [
    'state' => [
        'active' => 'Active',
        'inactive' => 'Inactive',
    ],
    'stage' => [
        'DRAFT' => 'Draft',
        'READY' => 'Ready',
        'ARCHIVED' => 'Archived',
    ],
    'column' => [
        'name' => 'Name',
        'name_ar' => 'Arabic Name',
        'name_en' => 'English Name',
        'position' => 'Position',
        'products' => 'Products',
        'state' => 'State',
        'actions' => 'Actions',
    ],
    'field' => [
        'name_ar' => 'Arabic Name',
        'name_en' => 'English Name',
        'position' => 'Position',
        'position_helper' => 'From 0 to 10,000, lowest first.',
        'slug_ar' => 'Arabic Address',
        'slug_en' => 'English Address',
    ],
    'choose' => 'Choose one',
    // Why every button of a shared list is out of reach for a reader without All Stores (P2).
    'all_stores' => 'Changing this list needs your job in All Stores.',
    'addresses' => [
        'title' => 'Web Addresses',
        'helper' => 'Left empty, it is made from the name.',
    ],
    'marks' => [
        'helper' => 'A blank line starts a paragraph, "- " a list item, "# " a heading, and **bold** is bold.',
        'preview' => 'Preview',
    ],
    'image' => [
        'helper' => 'JPEG, PNG or WebP, within the media library\'s size limit.',
        'remove' => 'Remove Photo',
        // A file the server never received whole: most often one larger than it accepts.
        'not_arrived' => 'The file did not arrive. It may be larger than the server accepts; choose a smaller one.',
    ],
    'drag' => [
        'instructions' => 'Press Space or Enter to pick an item up, the arrow keys to move it, and Space or Enter again to drop it.',
        'role' => 'movable item',
        'reorder' => 'Move :item',
        'picked' => ':item picked up.',
        'moved' => ':item moved to position :position of :total.',
        'dropped' => ':item dropped at position :position of :total.',
        'cancelled' => ':item put back.',
    ],
    'fates' => [
        'title' => [
            'brand' => 'Deactivate :name',
            'category' => 'Deactivate :name',
        ],
        'body' => [
            'brand' => 'Every product of this brand, in any stage, is given its fate in one step.',
            'category' => 'Every product in this category and under it, in any stage, is given its fate in one step, and its sub-categories are deactivated with it.',
        ],
        'confirm' => [
            'brand' => 'Deactivate Brand',
            'category' => 'Deactivate Category',
        ],
        'hidden_note' => 'A hidden product cannot be ordered, and comes back by itself when this is activated again.',
        'loading' => 'Reading the products it reaches.',
        'none' => [
            'brand' => 'No product carries this brand.',
            'category' => 'No product sits in this category or under it.',
        ],
        'count' => 'Products it reaches: :count.',
        'going_with' => 'Deactivated with it: :names.',
        'every' => 'For Every Product',
        'choice' => [
            'HIDE' => 'Hide',
            'LEAVE' => 'Leave',
            'MOVE' => 'Move',
        ],
        'choice_body' => [
            'brand' => [
                'HIDE' => 'Hidden with the brand, and not orderable.',
                'MOVE' => 'Moved to another active brand.',
            ],
            'category' => [
                'HIDE' => 'Hidden with the category, and not orderable.',
                'LEAVE' => 'Left in the closed category: found by search, its brand page and a link, listed in no category page.',
                'MOVE' => 'Moved to another active category that holds no sub-categories.',
            ],
        ],
        'move_to' => [
            'brand' => 'Move To Brand',
            'category' => 'Move To Category',
        ],
        'choose' => 'Choose where',
        'each' => 'Choose Product by Product',
        'product' => 'Product',
        'stage' => 'Stage',
        'category' => 'Category',
        'its_fate' => 'Its Fate',
        'its_fate_of' => 'Fate of :name',
        'as_every' => 'As for every product',
    ],
];
