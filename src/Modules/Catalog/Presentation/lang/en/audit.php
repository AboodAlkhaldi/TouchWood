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

    'product.added' => 'Product created',
    'product.edited' => 'Product edited',
    'product.deleted' => 'Draft product deleted',

    'product.made_ready' => 'Product made ready',
    'product.archived' => 'Product archived',
    'product.restored' => 'Product restored',
    'variant.archived' => 'Variant archived',
    'variant.restored' => 'Variant restored',
    'product.gallery_changed' => 'Product photos changed',
    'product.photo_detached' => 'Product photo removed with its file',
    'product.search_words_changed' => 'Product search words changed',
    'product.filter_values_changed' => 'Product filter values changed',
    'product.relations_changed' => 'Related products changed',
    'variant.photos_changed' => 'Variant photos changed',
    'listing.chosen' => 'Store choice changed',
    'listing.terms_set' => 'Selling terms changed',
    'listing.unavailable_marked' => 'Marked not available now',
    'listing.unavailable_cleared' => 'Not available now cleared',
    'listing.labels_attached' => 'Store labels changed',
    'product.hidden' => 'Product hidden with its category or brand',
    'product.moved' => 'Product moved when its category or brand was deactivated',
    'product.shown' => 'Product shown again with its category or brand',
    'variant.photo_detached' => 'Variant photo removed with its file',

    'variant.added' => 'Variant added',
    'variant.edited' => 'Variant edited',
    'variant.deleted' => 'Draft variant deleted',
    'variant.code_corrected' => 'Variant code corrected',

    'word_pair.added' => 'Search word pair added',
    'word_pair.deleted' => 'Search word pair deleted',
];
