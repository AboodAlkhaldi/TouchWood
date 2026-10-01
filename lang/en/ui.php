<?php

declare(strict_types=1);

// The design system's own words (frontend.md 1.10): what Geist's components say on any screen -
// a modal's Cancel, a pager's Next, the typed confirmation's prompt. Every page carries this file
// (App\Http\Page::DESIGN_SYSTEM). Geist's writing rules apply: buttons in Title Case.
return [
    'cancel' => 'Cancel',
    'close' => 'Close',
    'done' => 'Done',
    'previous' => 'Previous',
    'next' => 'Next',
    'pages' => 'Pages',
    // An en dash inside the range (Geist's table rules).
    'range' => ':from–:to of :total',
    'load_more' => 'Load More',
    'breadcrumbs' => 'Breadcrumbs',
    'request_id' => 'Request ID',
    'show_password' => 'Show password',
    'hide_password' => 'Hide password',
    'to_confirm' => 'To confirm, type ":phrase"',
    'to_confirm_named' => 'To confirm, type the :label ":phrase"',
];
