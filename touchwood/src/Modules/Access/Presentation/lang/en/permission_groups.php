<?php

declare(strict_types=1);

// The business area an action is shown under, in the role editor and the admin menu
// (stage 2b, P2). One list serves both (owner, 2026-09-22).
//
// Round 3 #10 (owner, 2026-11-10: "keep"): every screen stays in its area; five areas take the design's
// shorter word - Products, Sales, Configuration, Staff, Library. The keys never change.

return [
    'staff_and_permissions' => 'Staff',
    'customers' => 'Customers',
    // The panel menu's Reports section (ops.md §4.15, the menu): a menu section, not an area of the role editor.
    'reports' => 'Reports',
    'store_settings' => 'Configuration',
    'media' => 'Library',
    'audit' => 'Audit Log',
    'system' => 'System',
    'catalog' => 'Products',
    'pricing' => 'Pricing and Campaigns',
    'orders' => 'Sales',
    'shipping' => 'Shipping',
    'payments' => 'Payments',
    'companies' => 'Company Approvals',
    'points' => 'Points',
    'sync' => 'Sync',
    'marketing' => 'Marketing',
    'support' => 'Support',
    'messages_and_reports' => 'Messages and Reports',
    'content' => 'Content',
];
