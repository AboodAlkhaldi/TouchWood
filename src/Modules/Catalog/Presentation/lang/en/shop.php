<?php

declare(strict_types=1);

// The shop's pages (catalog.md §4.5): the menu and the search box in every page's frame, a
// category's, a brand's and a product's pages, the search results. No price, no stock, no code.
// Geist's writing rules (frontend.md §1.10).
return [
    'frame' => [
        'all_categories' => 'All Categories',
        'categories' => 'Categories',
        'open_menu' => 'Open the Categories',
        'search' => 'Search',
        'search_placeholder' => 'Search products…',
        'see_all' => 'See All Results',
        'no_suggestions' => 'No products match yet.',
        'all_of' => 'All of :name',
    ],
    'home' => 'Home',
    'last_pieces' => 'Last Pieces',
    'sub_categories' => 'Sub-Categories',
    'products' => 'Products',
    'filters' => [
        'title' => 'Filters',
        'brands' => 'Brands',
        'sides' => 'How It Is Sold',
        'retail' => 'Retail',
        'wholesale' => 'Wholesale',
        'clear_all' => 'Clear All',
        'remove' => 'Remove :name',
        'none' => 'Nothing here matches these filters.',
        'open' => 'Show Filters',
    ],
    'sort' => [
        'label' => 'Sort',
        'best-selling' => 'Best-Selling',
        'newest' => 'Newest',
        // Where the store's lists show ratings (amendment 25; feedback.md §4.1 F7).
        'rating' => 'Best Rated',
    ],
    // A card's stars, read aloud (feedback.md §4.1 F7).
    'card_rating' => ['zero' => ':rating out of 5 stars, :count reviews', 'one' => ':rating out of 5 stars, :count review', 'two' => ':rating out of 5 stars, :count reviews', 'few' => ':rating out of 5 stars, :count reviews', 'many' => ':rating out of 5 stars, :count reviews', 'other' => ':rating out of 5 stars, :count reviews'],
    // A card's Save heart (feedback.md §4.1 F7; amendment 32).
    'card_save' => ['add' => 'Save :name', 'remove' => 'Remove :name from saved'],
    'empty' => 'Nothing is listed here yet.',
    'product' => [
        'photo' => 'Photo :number of :total',
        'details' => 'Details',
        'weight' => 'Weight',
        'size' => 'Size',
        'grams' => ':value g',
        'size_value' => ':length × :width × :height mm',
        'description' => 'Description',
        'warranty' => 'Warranty',
        'lifetime' => 'Lifetime',
        'months' => ':count months',
        'one_month' => '1 month',
        'how_sold' => 'How It Is Sold',
        'retail' => 'Retail',
        'wholesale' => 'Wholesale',
        'at_least' => 'At least :count',
        'at_most' => 'at most :count',
        'goes_with' => 'Goes With',
        'may_also_like' => 'You May Also Like',
        'not_available' => 'Not Available',
        'not_available_text' => 'This product cannot be ordered here now.',
        'unavailable_value' => ':name, not available',
    ],
    'search' => [
        'title' => 'Search',
        'one' => '1 result for “:query”',
        'many' => ':count results for “:query”',
        'none' => 'No products match “:query”.',
        'browse' => 'Browse the categories instead:',
        'short' => 'Type at least two letters to search.',
    ],
    'not_found' => [
        'title' => 'Page Not Found',
        'back' => 'Back to the Store',
    ],
];
