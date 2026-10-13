<?php

declare(strict_types=1);

// What each audited Inventory action is called, for the audit log (frontend.md 3.5, E6). An action
// with no line here is shown exactly as it is recorded.
return [
    'stock.counted' => 'Stock counted',
    'stock.adjusted' => 'Stock added or removed',
    'stock.threshold_set' => 'Low-stock threshold set',
    'stock.stock_dependent_set' => 'Stock-dependent switched',
    'store_product.ending_soon_set' => '"Last pieces" switched',
];
