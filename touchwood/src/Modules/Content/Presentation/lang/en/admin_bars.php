<?php

declare(strict_types=1);

// W2 · Bars (content.md §4.4, §1.5, §1.9). Wording approved for now; the owner checks the whole site's wording later.
return [
    'title' => 'Bars',
    'subtitle' => 'The line above the shop\'s header, and short messages above product lists.',
    'tabs' => [
        'announcement' => 'Announcement Bar',
        'smart' => 'Smart Bars',
    ],
    'bar' => [
        'on' => 'Show the Bar',
        'on_helper' => 'Shown above the header on every page of the shop, inside its dates.',
        'text_ar' => 'Text (Arabic)',
        'text_en' => 'Text (English)',
        'text_rule' => 'One line, 120 characters at most.',
        'save' => 'Save Bar',
        'saved' => 'Bar saved',
    ],
    'smart' => [
        'add' => 'Add Smart Bar',
        'edit' => 'Edit Smart Bar',
        'empty' => 'No smart bars yet.',
        'empty_body' => 'A smart bar shows a short message above a category\'s products, or above every list while a campaign runs.',
        'columns' => [
            'message' => 'Message',
            'shown_on' => 'Shown On',
            'state' => 'State',
            'starts' => 'Start',
            'ends' => 'End',
        ],
        'message_ar' => 'Message (Arabic)',
        'message_en' => 'Message (English)',
        'message_rule' => 'One line, 100 characters at most.',
        'shown_on' => 'Shown On',
        'on_category' => 'A Category',
        'on_campaign' => 'A Campaign',
        'category' => 'Category',
        'category_search' => 'Search categories',
        'category_none' => 'No category matches ":query".',
        'category_helper' => 'Shown above this category\'s list and the lists of the categories under it.',
        'campaign_none' => 'No campaign is scheduled or live in this store.',
        'all_lists' => 'Every product list while the campaign runs',
        'no_dates' => 'Always',
        'save' => 'Save Smart Bar',
        'saved' => 'Smart bar saved',
        'menu' => [
            'edit' => 'Edit',
            'delete' => 'Delete Smart Bar',
        ],
        'delete' => [
            'title' => 'Delete this smart bar?',
            'body' => 'It stops showing at once. This can\'t be undone.',
            'confirm' => 'Delete Smart Bar',
        ],
        'deleted' => 'Smart bar deleted',
    ],
];
