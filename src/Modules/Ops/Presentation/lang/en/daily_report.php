<?php

declare(strict_types=1);

/*
| The daily report's words (ops.md §1.11), for the owner's approval with its design. Numbers are written
| in Latin digits (owner, 2026-10-06); :placeholders are filled in the view.
*/
return [
    'title' => 'Daily report',
    'email' => [
        'subject' => 'Daily report — :store — :day',
        'hello' => 'Hello :name,',
        'attached' => 'The daily report of :store for :day, until the time you chose, is attached.',
        'kept' => 'It is kept in the panel under Daily reports, to open again as it is.',
    ],
    'all_stores' => 'All stores',
    'switched_off' => 'Switched off',
    'today_until' => 'Today, from midnight until :time',
    'whole_day' => 'The whole day, midnight to midnight',
    'made_at' => 'Made :date at :time',
    'timezone' => 'Times in :zone',
    'page' => 'Page',
    'of' => 'of',
    'store_section' => 'Store',
    'none' => 'None',

    'cards' => [
        'revenue' => 'Revenue without VAT',
        'orders' => 'Orders',
        'average' => 'Average order',
        'new_customers' => 'New customers',
    ],

    'compare' => [
        'title' => 'Today against yesterday, same hours',
        'today' => 'Today',
        'yesterday' => 'Yesterday',
        'change' => 'Change',
        'orders' => 'Orders',
        'revenue' => 'Revenue without VAT',
        'new_customers' => 'New customers',
    ],

    'sales' => [
        'title' => 'Sales',
        'orders' => 'Orders',
        'placed' => 'Placed',
        'counted' => 'Counted as sales',
        'cancelled' => 'Cancelled',
        'rejected' => 'Rejected',
        'came_back' => 'Came back',
        'individuals' => 'By individuals',
        'companies' => 'By companies',
        'amounts' => 'Amounts',
        'revenue' => 'Revenue without VAT',
        'vat' => 'VAT',
        'revenue_with_vat' => 'Revenue with VAT',
        'waits_sales' => 'Waits for Sales',
        'shipping' => 'Shipping charged',
        'refunds' => 'Refunds',
        'average' => 'Average order (without VAT)',
        'if_refunds' => 'Revenue if the waiting refunds go through',
        'waiting_note' => 'Revenue is after the refunds of the returns asked today and completed; the refunds still waiting, below, come off only in the line above them.',
        'waiting_title' => 'Waiting refunds',
        'order' => 'Order',
        'estimate' => 'Estimated refund',
    ],

    'money' => [
        'title' => 'Money received',
        'payments_in' => 'Payments in',
        'refunds_out' => 'Refunds sent',
        'way' => 'Way',
        'records' => 'Records',
        'amount' => 'Amount',
        'ways' => [
            'ONLINE' => 'Online',
            'BANK_TRANSFER' => 'Bank transfers',
            'OTHER' => 'Other',
        ],
        'total' => 'Total',
        'transfers_waiting' => 'Transfers waiting',
        'transfers_waiting_line' => 'Orders: :orders, owed :amount',
        'transfers_overdue' => 'Transfers overdue',
        'waits_stage7' => 'Waits for Payments (stage 7)',
        'no_money' => 'No money moved today.',
    ],

    'products' => [
        'title' => 'Products',
        'top_sizes' => 'The 10 sizes sold most',
        'product' => 'Product',
        'units' => 'Units',
        'revenue' => 'Revenue',
        'by_category' => 'Revenue per category',
        'category' => 'Category',
        'no_category' => 'No category',
        'no_sales' => 'No sizes sold today.',
    ],

    'customers' => [
        'title' => 'Customers',
        'new_individuals' => 'New individuals',
        'new_companies' => 'New companies',
        'applications_submitted' => 'Company applications submitted',
        'applications_approved' => 'Company applications approved',
    ],

    'stock' => [
        'title' => 'Stock',
        'became_low' => 'Sizes that became low',
        'ran_out' => 'Sizes that ran out',
        'came_back' => 'Sizes back in stock',
        'reads' => 'Stock reads from the store system',
        'reads_done' => 'Reads done',
        'reads_failed' => 'Reads failed',
        'problems' => 'Problems opened',
        'not_complete' => 'Older than Sync keeps: not complete.',
    ],

    'promotions' => [
        'title' => 'Promotions and points',
        'coupons_used' => 'Coupons used',
        'coupon_discount' => 'Their discount',
        'points_given' => 'Points given',
        'points_spent' => 'Points spent',
        'other_points' => 'Other point changes',
        'entries' => 'Entries',
        'points' => 'Points',
        'kinds' => [
            'REDEMPTION_RETURNED' => 'Spent points returned (order cancelled or returned)',
            'EARNING_REVERSED' => 'Earned points taken back (order cancelled or returned)',
            'DEDUCTED' => 'Deducted by hand',
            'EXPIRED' => 'Expired',
        ],
    ],

    'feedback' => [
        'title' => 'Feedback',
        'new_reviews' => 'New reviews',
        'new_questions' => 'New questions',
        'waiting_questions' => 'Questions waiting for an answer',
    ],

    'searches' => [
        'title' => 'Searches',
        'searches' => 'Searches',
        'no_results' => 'Searches with no results',
        'top' => 'Searched most',
        'top_no_results' => 'Found nothing',
        'phrase' => 'Phrase',
        'times' => 'Times',
        'none' => 'No searches today.',
    ],

    'month' => [
        'title' => 'Month to date',
        'since' => 'From the 1st until the end of this report',
        'orders' => 'Orders',
        'revenue' => 'Revenue without VAT',
        'new_customers' => 'New customers',
    ],

    'totals' => [
        'title' => 'All stores — totals per currency',
        'note' => 'Each currency apart, never converted.',
        'stores' => 'Stores: :count',
        'payments_in' => 'Payments in',
    ],
];
