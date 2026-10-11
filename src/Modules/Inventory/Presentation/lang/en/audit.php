<?php

declare(strict_types=1);

// What each audited Inventory action is called, for the audit log (frontend.md 3.5, E6). An action
// with no line here is shown exactly as it is recorded.
return [
    'stock.counted' => 'Stock counted',
    'stock.adjusted' => 'Stock added or removed',
    'threshold.set' => 'Low-stock threshold set',
    'ending_soon.set' => '"Last pieces" switched',
    'stock_dependent.set' => 'Stock-dependent switched',
];
