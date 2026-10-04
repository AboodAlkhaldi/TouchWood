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
    'product_not_found' => [
        'title' => 'Product not found',
        'detail' => 'That product does not exist.',
    ],
    'variant_not_found' => [
        'title' => 'Variant not found',
        'detail' => 'That variant does not exist.',
    ],
    'code_taken' => [
        'title' => 'Code already used',
        'detail' => 'The code :code belongs to another product.',
    ],
    'duplicate_combination' => [
        'title' => 'Variant already there',
        'detail' => 'Another variant of this product has the same values. Restore it, or choose other values.',
    ],
    'attribute_set_locked' => [
        'title' => 'Attribute set cannot change',
        'detail' => 'This product has variants, so its attribute set stays as it is.',
    ],
    'category_not_lowest' => [
        'title' => 'Choose a lower category',
        'detail' => 'Products go only into a category with no sub-categories.',
    ],
    'category_holds_products' => [
        'title' => 'Category holds products',
        'detail' => 'This category holds products, so it takes no sub-category. Move the products first.',
    ],
    'brand_in_use' => [
        'title' => 'Brand still in use',
        'detail' => 'Products carry this brand. Move them to another brand first, or deactivate it.',
    ],
    'invalid_stage_change' => [
        'title' => 'Not possible now',
        'detail' => 'This is not possible at the product\'s current stage.',
    ],
    'attribute_set_in_use' => [
        'title' => 'Set in use',
        'detail' => 'Products with variants use this set, so its attributes stay. Rename it, or make a new set for new products.',
    ],
    'too_many' => [
        'title' => 'Too many',
        'detail' => 'At most :max :attribute.',
    ],
    'product_not_ready' => [
        'title' => 'Product not ready',
        'detail' => 'The product still needs some details before it can be shown. Fill them in, then try again.',
    ],
    'product_archived' => [
        'title' => 'Product archived',
        'detail' => 'This product is archived. Restore it before making it ready, or deleting it or one of its variants.',
    ],
    'not_chosen_in_store' => [
        'title' => 'Not chosen in this store',
        'detail' => 'This store has not chosen that product. Choose it first.',
    ],
    'invalid_selling_terms' => [
        'title' => 'Check the selling terms',
        'detail' => 'Each variant needs a selling mode, a maximum is never below its minimum, and wholesale needs its minimum.',
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
        'code' => 'code',
        'details' => 'details',
        'filter_values' => 'filter values',
        'height_mm' => 'height',
        'length_mm' => 'length',
        'number' => 'number',
        'photos' => 'photos',
        'relations' => 'related products',
        'search_words' => 'search words',
        'text_ar' => 'Arabic text',
        'text_en' => 'English text',
        'values' => 'values',
        'weight_grams' => 'weight',
        'width_mm' => 'width',
        'labels' => 'labels',
        'modes' => 'selling modes',
        'product' => 'product',
        'variants' => 'variants',
    ],
];
