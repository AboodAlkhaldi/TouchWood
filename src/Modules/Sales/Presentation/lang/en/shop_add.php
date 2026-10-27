<?php

declare(strict_types=1);

// Sales on a product's page: Add to Cart (sales.md §4.1 S2). Apart from the cart's words, which the page does not need.
return [
    'mode' => ['RETAIL' => 'Retail', 'WHOLESALE' => 'Wholesale'],
    'pieces' => ['zero' => ':count pieces', 'one' => ':count piece', 'two' => ':count pieces', 'few' => ':count pieces', 'many' => ':count pieces', 'other' => ':count pieces'],
    'add' => [
        'mode' => 'Buy as',
        'quantity' => 'Pieces',
        'limits' => ':minimum to :maximum pieces',
        'from' => 'At least :minimum pieces',
        'add' => 'Add to Cart',
        'choose' => 'Choose a size first',
        'added' => 'Added to cart · :pieces',
        'view' => 'View Cart',
    ],
];
