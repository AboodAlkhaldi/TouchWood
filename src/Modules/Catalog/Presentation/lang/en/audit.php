<?php

declare(strict_types=1);

// What each audited Catalog action is called, for the audit log (frontend.md 3.5, E6). An action
// with no line here is shown exactly as it is recorded.
return [
    'brand.added' => 'Brand added',
    'brand.edited' => 'Brand edited',
    'brand.made_default' => 'Brand made the default',
    'brand.default_moved' => 'Brand no longer the default',
    'brand.deactivated' => 'Brand deactivated',
    'brand.activated' => 'Brand reactivated',
    'brand.deleted' => 'Brand deleted',
    'brand.logo_detached' => 'Brand logo removed with its file',

    'category.added' => 'Category added',
    'category.edited' => 'Category edited',
    'category.moved' => 'Category moved',
    'category.deactivated' => 'Category deactivated',
    'category.activated' => 'Category reactivated',
    'category.deleted' => 'Category deleted',
    'category.ranked' => 'Category placed in the store menu',
    'category.image_detached' => 'Category photo removed with its file',

    'attribute.added' => 'Attribute added',
    'attribute.edited' => 'Attribute edited',
    'attribute.deactivated' => 'Attribute deactivated',
    'attribute.activated' => 'Attribute reactivated',
    'attribute.deleted' => 'Attribute deleted',

    'attribute_value.added' => 'Attribute value added',
    'attribute_value.edited' => 'Attribute value edited',
    'attribute_value.deactivated' => 'Attribute value deactivated',
    'attribute_value.activated' => 'Attribute value reactivated',
    'attribute_value.deleted' => 'Attribute value deleted',

    'attribute_set.added' => 'Attribute set added',
    'attribute_set.edited' => 'Attribute set edited',
    'attribute_set.deactivated' => 'Attribute set deactivated',
    'attribute_set.activated' => 'Attribute set reactivated',
    'attribute_set.deleted' => 'Attribute set deleted',

    'label.added' => 'Label added',
    'label.edited' => 'Label edited',
    'label.deactivated' => 'Label deactivated',
    'label.activated' => 'Label reactivated',
    'label.deleted' => 'Label deleted',

    'warranty.added' => 'Warranty added',
    'warranty.edited' => 'Warranty edited',
    'warranty.deactivated' => 'Warranty deactivated',
    'warranty.activated' => 'Warranty reactivated',
    'warranty.deleted' => 'Warranty deleted',

    'word_pair.added' => 'Search word pair added',
    'word_pair.deleted' => 'Search word pair deleted',
];
