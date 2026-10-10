<?php

declare(strict_types=1);

// The names of Inventory's permissions (InventoryPermissions): the one job the role editor shows
// under Catalog and Variants, named as the owner gave it (2026-10-09; the Arabic is proposed), and the
// system's two.
return [
    'stock' => [
        'manage' => 'Manage Stock',
    ],
    'holds' => [
        'expire' => 'Free Expired Holds',
    ],
    'orderable' => [
        'rebuild' => "Push Every Size's Orderability Again",
    ],
];
