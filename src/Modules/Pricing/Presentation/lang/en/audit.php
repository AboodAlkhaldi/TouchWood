<?php

declare(strict_types=1);

// What each audited Pricing action is called, for the audit log (frontend.md 3.5, E6). An action
// with no line here is shown exactly as it is recorded.
return [
    'retail_price.set' => 'Retail price set',
    'retail_price.filed' => 'Retail price set by a store file',
    'retail_price.provided' => 'Retail price set by the provider',
    'wholesale_prices.set' => 'Wholesale prices set',
    'category_discount.added' => 'Category discount added',
    'category_discount.changed' => 'Category discount changed',
    'category_discount.ended' => 'Category discount ended',
    'category_discount.removed' => 'Category discount removed',
    'retail_price.removed' => 'Retail price removed',
    'sale.added' => 'Sale added',
    'sale.changed' => 'Sale changed',
    'sale.ended' => 'Sale ended',
    'sale.removed' => 'Sale removed',
];
