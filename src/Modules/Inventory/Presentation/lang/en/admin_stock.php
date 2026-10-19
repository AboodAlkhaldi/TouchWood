<?php

declare(strict_types=1);

// The words of Inventory's screens (inventory.md §4.3.1 "Words"): Stock (I1), its dialogs and drawer, the
// product page's section, and what Low Stock shares with them. A count's forms are chosen by the page's plural
// rules (zero, one, two, few, many, other); the amber toast's by the server's (trans_choice).
return [
    'reasons' => [
        'wired' => "The provider sets this store's stock.",
        'archived' => 'Archived in the catalog. Restore it to change its stock.',
    ],

    'title' => 'Stock',
    'subtitle' => 'What each size has in this store, and what orders hold.',
    'wired' => [
        'label' => 'Provider',
        'body' => "This store's stock comes from its provider. Thresholds and which sizes count on stock are set here.",
    ],
    'showing' => 'Showing :shown of :total',
    'one_size' => 'One Size',
    'actions' => 'Actions',
    'to' => 'to',
    'clear_filters' => 'Clear Filters',
    'search' => 'Search sizes',
    'search_placeholder' => 'Search by name or code',
    'filters' => [
        'show' => 'Show',
        'ALL' => 'All',
        'LOW' => 'Low',
        'OUT_OF_STOCK' => 'Out of Stock',
        'HELD' => 'Held',
        'SHORT' => 'Short',
    ],
    'column' => [
        'select' => 'Select :size',
        'select_all' => 'Select every row shown',
        'photo' => 'Photo',
        'product' => 'Product',
        'size' => 'Size',
        'code' => 'Code',
        'in_stock' => 'In Stock',
        'held' => 'Held',
        'available' => 'Available',
        'threshold' => 'Threshold',
        'counts_on_stock' => 'Counts on Stock',
    ],
    'badge' => [
        'SHORT' => 'Short by :count',
        'OUT_OF_STOCK' => 'Out of Stock',
        'ORDERABLE' => 'Orderable',
        'LOW' => 'Low',
        'archived' => 'Archived',
        'not_switched_on' => 'Not Switched On',
        'last_pieces' => 'Last Pieces',
    ],
    'threshold_default' => ':count (Default)',
    'from_provider' => 'From Provider',
    'counts_on_label' => 'Counts on stock: :size',

    // The counts typed in the rows and their save bar (§4.3.1, S5, Q40).
    'box' => [
        'label' => 'Count for :size',
        'label_store' => 'Count for :size in :store',
        'changed' => [
            'zero' => ':count counts changed',
            'one' => ':count count changed',
            'two' => ':count counts changed',
            'few' => ':count counts changed',
            'many' => ':count counts changed',
            'other' => ':count counts changed',
        ],
        'lost' => [
            'zero' => ':count changed counts will be lost.',
            'one' => ':count changed count will be lost.',
            'two' => ':count changed counts will be lost.',
            'few' => ':count changed counts will be lost.',
            'many' => ':count changed counts will be lost.',
            'other' => ':count changed counts will be lost.',
        ],
        'save' => 'Save Counts',
        'failed' => 'Failed to save counts. Check the connection and try again.',
        'leaves_short' => [
            'zero' => ':count orders hold :pieces of these. This count leaves them short.',
            'one' => ':count order holds :pieces of these. This count leaves it short.',
            'two' => ':count orders hold :pieces of these. This count leaves them short.',
            'few' => ':count orders hold :pieces of these. This count leaves them short.',
            'many' => ':count orders hold :pieces of these. This count leaves them short.',
            'other' => ':count orders hold :pieces of these. This count leaves them short.',
        ],
    ],
    'check' => [
        'count' => 'Count must be a whole number from 0 to 2,147,483,647.',
        'quantity' => 'Quantity must be 1 or more.',
        'quantity_max' => "Quantity can't be more than :max, what's in stock.",
        'threshold' => 'Threshold must be from 0 to 100,000.',
        'note_required' => 'Note is required for a correction.',
        'note_long' => 'Note can be at most 500 characters.',
        'direction' => 'Choose Add or Remove.',
        'reason' => 'Choose a reason.',
    ],

    // Selecting rows (shadcn's data-table pattern).
    'selected' => [
        'zero' => ':count selected',
        'one' => ':count selected',
        'two' => ':count selected',
        'few' => ':count selected',
        'many' => ':count selected',
        'other' => ':count selected',
    ],
    'set_threshold_for' => [
        'zero' => 'Set Threshold for :count Sizes…',
        'one' => 'Set Threshold for :count Size…',
        'two' => 'Set Threshold for :count Sizes…',
        'few' => 'Set Threshold for :count Sizes…',
        'many' => 'Set Threshold for :count Sizes…',
        'other' => 'Set Threshold for :count Sizes…',
    ],
    'count_on' => 'Count on Stock',
    'dont_count' => "Don't Count on Stock",
    'clear_selection' => 'Clear Selection',

    // A row's ⋯ menu.
    'menu' => [
        'view' => 'View Stock',
        'adjust' => 'Add or Remove Stock…',
        'threshold' => 'Set Threshold…',
        'show_last' => 'Show Last Pieces',
        'hide_last' => 'Hide Last Pieces',
        'last_helper' => "Marks the product 'last pieces' while its sizes are at or below their threshold.",
    ],

    // Add or Remove Stock.
    'adjust' => [
        'title' => 'Add or Remove Stock',
        'body' => 'Change the stock of :size, with its reason.',
        'direction' => 'Add or Remove',
        'add' => 'Add',
        'remove' => 'Remove',
        'quantity' => 'Quantity',
        'reason' => 'Reason',
        'note' => 'Note',
        'becomes' => 'In stock becomes :count.',
        'leaves_short' => [
            'zero' => ':count orders hold :pieces of these. This leaves them short.',
            'one' => ':count order holds :pieces of these. This leaves it short.',
            'two' => ':count orders hold :pieces of these. This leaves them short.',
            'few' => ':count orders hold :pieces of these. This leaves them short.',
            'many' => ':count orders hold :pieces of these. This leaves them short.',
            'other' => ':count orders hold :pieces of these. This leaves them short.',
        ],
        'add_button' => 'Add Stock',
        'remove_button' => 'Remove Stock',
    ],
    'reason' => [
        'STOCKTAKE' => 'Stocktake',
        'RECEIVED' => 'Received',
        'DAMAGED_LOST' => 'Damaged or Lost',
        'OFFLINE_SALE' => 'Offline Sale',
        'CORRECTION' => 'Correction',
        'SHIPPED' => 'Shipped',
        'RETURNED' => 'Returned',
        'FILE' => 'Store File',
        'PROVIDER' => 'Provider',
    ],

    // Set Threshold.
    'threshold' => [
        'title' => 'Set Threshold',
        'body_one' => 'The level :size counts as low at.',
        'body_many' => [
            'zero' => 'The level :count sizes count as low at.',
            'one' => 'The level :count size counts as low at.',
            'two' => 'The level :count sizes count as low at.',
            'few' => 'The level :count sizes count as low at.',
            'many' => 'The level :count sizes count as low at.',
            'other' => 'The level :count sizes count as low at.',
        ],
        'field' => 'Threshold',
        'helper' => "Empty uses the store's default, :default.",
        'save' => 'Save Threshold',
    ],

    // Add Size (Q24).
    'add_size' => [
        'button' => 'Add Size',
        'body' => "Stock a size this store doesn't sell - one only given as a gift. Its count is saved as a stocktake.",
        'size' => 'Size',
        'search' => 'Search ready sizes',
        'none' => 'No sizes match ":query"',
        'listed' => 'Already listed',
        'count' => 'Count',
        'save' => 'Count Size',
    ],

    // The drawer (Q3, Q21, Q22).
    'drawer' => [
        'figures' => 'Figures',
        'holds' => 'Holds',
        'holds_line' => [
            'zero' => 'Held by :count orders: :pieces pieces (:bought bought, :gift gift)',
            'one' => 'Held by :count order: :pieces pieces (:bought bought, :gift gift)',
            'two' => 'Held by :count orders: :pieces pieces (:bought bought, :gift gift)',
            'few' => 'Held by :count orders: :pieces pieces (:bought bought, :gift gift)',
            'many' => 'Held by :count orders: :pieces pieces (:bought bought, :gift gift)',
            'other' => 'Held by :count orders: :pieces pieces (:bought bought, :gift gift)',
        ],
        'no_holds' => 'No order holds this size.',
        'history' => 'History',
        'no_history' => 'No changes yet.',
        'last_pieces' => 'Last Pieces',
        'shown' => 'Shown',
        'hidden' => 'Hidden',
        'who' => [
            'staff_hidden' => 'A Staff Member',
            'PROVIDER' => 'The Provider',
            'FILE' => 'A Store File',
            'FILE_named' => 'A Store File, switched on by :name',
            'ORDER' => 'An Order',
            'SYSTEM' => 'The System',
        ],
    ],

    'section' => [
        'title' => 'Stock',
        'last_pieces' => 'Last Pieces',
        'last_pieces_helper' => "Marks the product 'last pieces' while its sizes are at or below their threshold.",
    ],

    'empty' => [
        'title' => 'No sizes in this store',
        'body' => 'Sizes appear here once the store switches them on.',
        'filtered_title' => 'No sizes match these filters',
        'filtered_body' => 'Clear the filters to see every size.',
    ],

    'failed' => [
        'history' => 'Failed to load the history. Try again.',
        'sizes' => 'Failed to load the sizes. Try again.',
        'size' => 'Failed to load the size. Try again.',
        'try_again' => 'Try Again',
    ],

    'toast' => [
        'counts_saved' => 'Counts saved',
        'added' => 'Stock added',
        'removed' => 'Stock removed',
        'threshold_saved' => 'Threshold saved',
        'thresholds_saved' => 'Thresholds saved',
        'last_pieces_shown' => 'Last pieces shown',
        'last_pieces_hidden' => 'Last pieces hidden',
        'dependency_on' => 'Stock dependency turned on',
        'dependency_off' => 'Stock dependency turned off',
        // The amber toast (§4.3.1): a sentence a size left short.
        'short' => '{1} :count order is short of :size|[0,*] :count orders are short of :size',
    ],
];
