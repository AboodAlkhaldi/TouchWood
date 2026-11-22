<?php

declare(strict_types=1);

// The Reports page (ops.md §4.15 O8). Wording approved for now; the owner checks the whole site's wording later.
// A report's own words - its title, sheets, columns and notes - are ops::reports, in the reader's account language.
return [
    'title' => 'Reports',
    'subtitle' => 'Choose a report, its store and its days, then download it as an Excel file.',
    'kinds_label' => 'Reports',
    'kinds' => [
        'revenue' => 'Revenue',
        'product-sales' => 'Product Sales',
        'stock' => 'Stock',
        'searches' => 'Searches',
        'favourites' => 'Favourites',
    ],
    'choices_label' => 'Report choices',
    'days' => 'Days',
    'any_date' => 'Choose Days',
    'preset_today' => 'Today',
    'preset_week' => 'Last 7 Days',
    'preset_month' => 'Last 30 Days',
    'preset_month_to_date' => 'Month to Date',
    'days_helper' => 'Days are read in each store\'s own time, not UTC.',
    'as_now' => 'This report shows things as they stand now, so it has no days to choose.',
    'by' => 'Group By',
    'by_day' => 'By Day',
    'by_month' => 'By Month',
    'show' => 'Show Report',
    'download' => 'Download Excel',
    'before' => 'Choose the store and the days, then Show Report.',
    'before_now' => 'Choose the store, then Show Report.',
    'changed' => 'Choices changed. Show Report again.',
    'language' => [
        'ar' => 'This report is in Arabic, your account\'s language.',
        'en' => 'This report is in English, your account\'s language.',
    ],
    'sheets_label' => 'Sheets',
    'empty' => 'Nothing for these days.',
    'more' => 'Showing the first 50 rows. Every row is in the Excel file.',
];
