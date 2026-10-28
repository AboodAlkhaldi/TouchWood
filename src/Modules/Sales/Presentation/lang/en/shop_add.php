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
        'not_sold' => 'This size can\'t be ordered now.',
        'out_of_stock' => 'Out of stock',
        'full' => 'Your cart already holds the most you can buy of this size.',
        'in_cart' => ['zero' => ':count pieces in your cart', 'one' => ':count piece in your cart', 'two' => ':count pieces in your cart', 'few' => ':count pieces in your cart', 'many' => ':count pieces in your cart', 'other' => ':count pieces in your cart'],
    ],
    // The wholesale prices by quantity, under the Wholesale choice (§4.1 S1; R2-3).
    'bands' => [
        'title' => 'Wholesale Prices',
        'from' => ['zero' => 'From :count pieces', 'one' => 'From :count piece', 'two' => 'From :count pieces', 'few' => 'From :count pieces', 'many' => 'From :count pieces', 'other' => 'From :count pieces'],
    ],
];
