<?php

declare(strict_types=1);

// W11 · Redirects and W11b, the checked sheet (content.md §4.4, §1.15). Wording approved for now; the owner checks the
// whole site's wording later.
return [
    'title' => 'Redirects',
    'subtitle' => 'Forward old addresses to new ones.',
    'empty' => 'No redirects yet.',
    'empty_body' => 'Forward old addresses to new ones.',
    'search' => 'Search Addresses',
    'search_placeholder' => 'Search old and new addresses',
    'upload' => 'Upload Sheet',
    'columns' => [
        'old' => 'Old Address',
        'new' => 'New Address',
        'visits' => 'Visits',
        'last_visit' => 'Last Visit',
        'check' => 'Check',
    ],
    'result' => [
        'FINE' => 'Fine',
        'FORWARDS' => 'Forwards',
        'NOT_FOUND' => 'Not Found',
        'STORE_OFF' => 'Store Off',
        'CANT_BE_REACHED' => 'Can\'t Be Reached',
    ],
    'forwards_to' => 'Forwards to :path',
    'menu' => [
        'change' => 'Change',
        'reset' => 'Reset Count',
        'final' => 'Use Final Address',
        'delete' => 'Delete Line',
        'add' => 'Add Line',
        'check' => 'Check Addresses',
        'page_actions' => 'More Actions',
    ],
    'line' => [
        'add_title' => 'Add a line',
        'change_title' => 'Change this line',
        'body' => 'The old address is a path on this site, the new one a page of the shop with its store and language.',
        'old' => 'Old Address',
        'new' => 'New Address',
        'save' => 'Save Line',
    ],
    'reset' => [
        'title' => 'Reset this line\'s count?',
        'body' => 'Its visits go back to 0 and its last visit is cleared.',
        'confirm' => 'Reset Count',
    ],
    'delete' => [
        'title' => 'Delete this line?',
        'body' => 'The old address answers Not Found from now on. This can\'t be undone.',
        'confirm' => 'Delete Line',
    ],
    'added' => 'Line added',
    'changed' => 'Line changed',
    'reset_done' => 'Count reset',
    'final_done' => 'Final address used',
    'deleted' => 'Line deleted',
    'check' => [
        'queued' => 'Address check queued',
        'waits' => 'A check is already queued.',
    ],
    'sheet' => [
        'choose' => 'Choose a Sheet',
        'helper' => 'An xlsx or csv file, its first row the headings Old Address and New Address, 5,000 lines and 2 MB at most.',
        'title' => 'Checked Sheet',
        'subtitle' => 'What the sheet would do, line by line. Nothing is written until Apply Sheet.',
        'tabs' => [
            'NEW' => 'New',
            'SAME' => 'Same',
            'CHANGE' => 'Change',
            'REFUSED' => 'Refused',
        ],
        'columns' => [
            'line' => 'Row',
            'old' => 'Old Address',
            'new' => 'New Address',
            'reason' => 'Why',
        ],
        'none' => 'No lines here.',
        'apply' => 'Apply Sheet',
        'apply_title' => 'Write :count lines?',
        'apply_body' => 'New lines are added and changed lines take their new address. No line is deleted.',
        'nothing' => 'The sheet writes no line.',
        'applied_already' => 'This sheet was applied.',
        'applied' => 'Sheet applied: :added added, :changed changed',
        'back' => 'Back to Redirects',
    ],
];
