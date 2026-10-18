<?php

declare(strict_types=1);

// What Inventory's queued work is called on the failed jobs screen (frontend.md E7).
return [
    'release_expired_holds' => 'Freeing the stock held by orders whose time ran out',
    'count_low_stock' => 'Counting the low sizes of a store again for the menu',
    'set_stock_from_file' => 'Setting the stock a store file gave',
];
