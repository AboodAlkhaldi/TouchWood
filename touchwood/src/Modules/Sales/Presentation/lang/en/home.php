<?php

declare(strict_types=1);

// Sales' sections of the admin home (dashboard.md §2 B-J; flags-2026-11.md S3.3 F17). "Sales", never "Revenue"
// (the owner, §1 #2): what was sold at our selling price.
return [
    'sales' => [
        'title' => 'Sales',
        // Under the figure, not taken off it (D7).
        'refunds' => 'Refunds :amount',
    ],
    'orders' => [
        'title' => 'Orders',
    ],
    'average_order' => [
        'title' => 'Average Order',
    ],
    'new_customers' => [
        'title' => 'New Customers',
    ],
    'by_day' => [
        'title' => 'Sales and Orders',
        'sales' => 'Sales',
        'orders' => 'Orders',
        'before' => 'Sales, period before',
    ],
    'audience' => [
        'title' => 'Individuals and Companies',
        'individuals' => 'Individuals',
        'companies' => 'Companies',
    ],
    'orders_now' => [
        'title' => 'Orders Now',
        'waiting' => 'Waiting',
        'to_ship' => 'To Ship',
        'on_the_way' => 'On the Way',
        'ended' => 'Cancelled and Came Back',
    ],
    'recent_orders' => [
        'title' => 'Recent Orders',
        'open' => 'View All Orders',
        'product' => 'Product',
        'customer' => 'Customer',
        'pieces' => 'Pieces',
        'way' => 'Way to Pay',
        'status' => 'Status',
        'total' => 'Total',
        'more' => '+:count more',
        'empty' => 'No orders yet.',
    ],
    'best_sellers' => [
        'title' => 'Best Sellers',
        'product' => 'Product',
        'rating' => 'Rating',
        'pieces' => 'Pieces Sold',
        'sales' => 'Sales',
        'empty' => 'Nothing sold in this period.',
    ],
    'distribution' => [
        'title' => 'Sales by Category and Money Received',
        'categories' => 'Sales by Category',
        'ways' => 'Money Received by Way',
        'other' => 'Other',
    ],
    'transfers_overdue' => [
        'title' => 'Transfers Overdue',
        'orders' => 'Orders',
        'stores' => 'By Store',
        'owed' => '{1} :count order, :amount owed|[2,*] :count orders, :amount owed',
        'open' => 'View Transfers',
    ],
    'returns_waiting' => [
        'title' => 'Returns Waiting',
        'waiting' => 'Returns',
        'stores' => 'By Store',
        'count' => '{1} :count return|[2,*] :count returns',
        'open' => 'View Returns',
    ],
    'terms_missing' => [
        'title' => 'No Terms and Conditions',
        'stores' => 'Stores',
        'store' => 'No Terms and Conditions: :store',
        'detail' => "Orders can't be placed until this store's terms are published.",
    ],
];
