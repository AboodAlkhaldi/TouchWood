<?php

declare(strict_types=1);

// The categories screen (catalog.md §1.5, §4.4 S2). Geist's writing rules (frontend.md §1.10).
return [
    'title' => 'Categories',
    'subtitle' => 'One tree for every store; products sit in the categories with no sub-categories.',
    'subtitle_store' => 'One tree for every store; the order shown is :store\'s menu, dragged among siblings.',
    'add' => 'Add Category',
    'add_body' => 'It goes last among its siblings, in every store.',
    'add_sub' => 'Add Sub-Category…',
    'add_sub_title' => 'Add Sub-Category',
    'edit' => 'Edit…',
    'edit_title' => 'Edit Category',
    'edit_body' => 'Every store shows the change at once.',
    'save' => 'Save Category',
    'move' => 'Move…',
    'move_title' => 'Move Category',
    'move_body' => 'Its addresses stay; it goes last among its new siblings, in every store.',
    'move_confirm' => 'Move Category',
    'activate' => 'Activate Category',
    'deactivate' => 'Deactivate Category…',
    'delete' => 'Delete Category…',
    'delete_title' => 'Delete Category',
    'delete_body' => ':name is deleted, and its addresses are free again.',
    'fold' => 'Fold :name',
    'unfold' => 'Unfold :name',
    'base_place' => 'Base Store\'s Place',
    'empty' => [
        'title' => 'No Categories Yet',
        'body' => 'Add the top categories first, then the ones under them.',
    ],
    'reason' => [
        'children' => 'It has sub-categories: move or delete them first.',
        'products' => 'Products sit in it or under it: move them first.',
        'holds_products' => 'Products sit in it: a category with products takes no sub-category.',
        'inactive' => 'It is inactive: activate it first.',
    ],
    'field' => [
        'parent' => 'Under',
        'top' => 'The top of the tree',
        'image' => 'Photo',
    ],
    'toast' => [
        'added' => 'Category added',
        'edited' => 'Category saved',
        'moved' => 'Category moved',
        'ranked' => 'Order saved',
        'activated' => 'Category activated',
        'deactivated' => 'Category deactivated',
        'deleted' => 'Category deleted',
    ],
];
