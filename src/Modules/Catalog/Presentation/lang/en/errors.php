<?php

declare(strict_types=1);

// Catalog's error messages, by error type (catalog.{key}), and the names of the fields they point at.
return [
    'invalid_catalog_attribute' => [
        'title' => 'Check the details',
        'detail' => 'The :attribute is not valid.',
    ],
    'brand_not_found' => [
        'title' => 'Brand not found',
        'detail' => 'That brand does not exist.',
    ],
    'category_not_found' => [
        'title' => 'Category not found',
        'detail' => 'That category does not exist.',
    ],
    'list_item_not_found' => [
        'title' => 'Not found',
        'detail' => 'That item is not in the list.',
    ],
    'slug_taken' => [
        'title' => 'Address already used',
        'detail' => 'The address :slug is used, or was used, by another. Choose another.',
    ],
    'name_taken' => [
        'title' => 'Already in the list',
        'detail' => 'That :attribute is already in the list.',
    ],
    'category_loop' => [
        'title' => 'Cannot move there',
        'detail' => 'A category cannot move under itself or under a category inside it.',
    ],
    'category_inactive' => [
        'title' => 'Category deactivated',
        'detail' => 'That category is deactivated. Activate it, or choose another.',
    ],
    'brand_inactive' => [
        'title' => 'Brand deactivated',
        'detail' => 'That brand is deactivated. Activate it, or choose another.',
    ],
    'list_item_inactive' => [
        'title' => 'Deactivated',
        'detail' => 'That item is deactivated. Activate it, or choose another.',
    ],
    'default_brand_required' => [
        'title' => 'This is the default brand',
        'detail' => 'Make another brand the default first.',
    ],
    'category_not_empty' => [
        'title' => 'Category not empty',
        'detail' => 'Move the products and categories inside it first.',
    ],
    'list_item_in_use' => [
        'title' => 'Still in use',
        'detail' => 'It is still in use, so it cannot be deleted. Deactivate it instead.',
    ],
    'attribute_kind_locked' => [
        'title' => 'Job cannot change',
        'detail' => 'This attribute has values, so its job stays as it is.',
    ],
    'fields' => [
        'agency_type' => 'agency type',
        'attribute' => 'attribute',
        'attribute_ids' => 'attributes',
        'description_ar' => 'Arabic description',
        'description_en' => 'English description',
        'image_media_id' => 'photo',
        'is_colour' => 'colour',
        'kind' => 'job',
        'logo_media_id' => 'logo',
        'name_ar' => 'Arabic name',
        'name_en' => 'English name',
        'origin_country' => 'country of origin',
        'parent_id' => 'parent category',
        'period_months' => 'warranty period',
        'position' => 'position',
        'rank' => 'place in the menu',
        'slug_ar' => 'Arabic address',
        'slug_en' => 'English address',
        'store' => 'store',
        'swatch' => 'swatch',
        'terms_ar' => 'Arabic terms',
        'terms_en' => 'English terms',
        'tone' => 'look',
        'unit_ar' => 'Arabic unit',
        'unit_en' => 'English unit',
        'value' => 'value',
        'word_a' => 'first word',
        'word_b' => 'second word',
        'word_pair' => 'word pair',
    ],
];
