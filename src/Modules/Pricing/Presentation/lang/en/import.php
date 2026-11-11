<?php

declare(strict_types=1);

// Pricing's lines on a store file's page (pricing.md §1.8).
return [
    'price_set' => 'The retail price will be set to :price :currency.',
    'price_ignored' => 'The price is ignored: this store takes its prices from its provider.',
    'price_too_precise' => 'The price :price cannot be set: the store\'s currency has :decimals decimals.',
    'price_not_positive' => 'The price :price cannot be set: it must be above 0.',
    'price_invalid' => 'The price :price cannot be set: it is not a number.',
];
