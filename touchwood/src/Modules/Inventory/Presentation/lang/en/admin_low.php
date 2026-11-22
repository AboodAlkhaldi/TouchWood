<?php

declare(strict_types=1);

// Low Stock (inventory.md §4.3 I2).
return [
    'title' => 'Low Stock',
    'subtitle' => 'Sizes at or below their threshold, the fewest left first.',
    'count_in' => [
        'zero' => ':count low in :store',
        'one' => ':count low in :store',
        'two' => ':count low in :store',
        'few' => ':count low in :store',
        'many' => ':count low in :store',
        'other' => ':count low in :store',
    ],
    'empty' => [
        'title' => 'Nothing is low',
        'body' => 'Sizes at or below their threshold appear here.',
    ],
];
