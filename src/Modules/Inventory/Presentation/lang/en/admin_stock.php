<?php

declare(strict_types=1);

// The words of Inventory's screens (inventory.md §4.3.1 "Words"). Step 4 writes the reasons a button
// is drawn disabled, as `InventoryActionsForReader` answers them; the pages' own words come with them.
return [
    'reasons' => [
        'wired' => "The provider sets this store's stock.",
        'archived' => 'Archived in the catalog. Restore it to change its stock.',
    ],
];
