<?php

declare(strict_types=1);

// The names of Pricing's permissions (PricingPermissions): the three jobs the role editor shows
// under Pricing and Campaigns, named as the owner accepted them (2026-10-08), and the system's three.
return [
    'price' => [
        'edit' => 'Edit Prices and Sales',
    ],
    'wholesale' => [
        'edit' => 'Edit Wholesale Prices',
    ],
    'category_discount' => [
        'manage' => 'Manage Category Discounts',
    ],
    'candidates' => [
        'rebuild' => "Recompute Every Size's Price Options",
    ],
    'windows' => [
        'apply' => 'Update Shop Prices When a Sale or Discount Starts or Ends',
        'check' => 'Daily Check That Shop Prices Followed Every Start and End',
    ],
];
