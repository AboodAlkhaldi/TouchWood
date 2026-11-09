<?php

declare(strict_types=1);

// The Daily Reports page (ops.md §4.15 O9). Wording approved for now; the owner checks the whole site's wording later.
return [
    'title' => 'Daily Reports',
    'subtitle' => 'A day\'s figures as a PDF, made when you ask or emailed to you each day.',
    'no_store' => 'No store to choose. A Super Admin can switch your store on.',
    'make' => [
        'title' => 'Make a Report',
        'store' => 'Store',
        'day' => 'Day',
        'today' => 'Today So Far',
        'past' => 'A Past Day',
        'date' => 'The Day',
        'button' => 'Make Report',
        'busy' => 'Making Report…',
        'toast' => 'Report ready.',
    ],
    'email' => [
        'title' => 'Daily Email',
        'on' => 'Send the daily email for :store',
        'time' => 'Time for :store',
        'rule' => '24-hour time, 00:01 to 23:59.',
        'next' => 'Next: :time',
        'saved' => 'Saved',
        'failed' => 'Couldn\'t save this. Try again.',
    ],
    'list' => [
        'title' => 'Reports Made',
        'empty' => 'No reports yet.',
        'past_day' => 'Past day',
        'none' => 'Not emailed',
        'download' => 'Download PDF',
        'columns' => [
            'store' => 'Store',
            'day' => 'Day',
            'made_by' => 'Made By',
            'made' => 'Made',
            'language' => 'Language',
            'size' => 'Size',
            'emailed' => 'Emailed',
            'file' => 'File',
        ],
    ],
    'language' => [
        'ar' => 'Arabic',
        'en' => 'English',
    ],
];
