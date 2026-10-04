<?php

declare(strict_types=1);

// The design system's own words (frontend.md 1.10): what Geist's components say on any screen -
// a modal's Cancel, a pager's Next, the typed confirmation's prompt. Every page carries this file
// (App\Http\Page::DESIGN_SYSTEM). Geist's writing rules apply: buttons in Title Case.
return [
    'cancel' => 'Cancel',
    'previous' => 'Previous',
    'next' => 'Next',
    'pages' => 'Pages',
    // An en dash inside the range (Geist's table rules).
    'range' => ':from–:to of :total',
    // The owner's word for the next page of a keyset list (frontend.md E7), in Title Case.
    'load_more' => 'Show More',
    // A busy button's spinner, which shadcn names in English; given as a prop, not edited.
    'loading' => 'Loading',
    // Geist's Copy Button, said to a screen reader once the text is on the clipboard.
    'copied' => 'Copied',
    'copy_failed' => "Couldn't copy. Select the text and copy it by hand.",
    // The search field's clear button (Geist's Search Input), named for a screen reader.
    'clear_search' => 'Clear Search',
    // The ⋯ button that holds a page's or a row's other actions (Geist's Dots Menu), named for a
    // screen reader.
    'more_actions' => 'More Actions',
    'breadcrumbs' => 'Breadcrumbs',
    // The phone's sidebar, which shadcn names in English (frontend.md §1.11, edit 5).
    'sidebar' => 'Sidebar',
    'sidebar_description' => 'Displays the mobile sidebar.',
    'show_password' => 'Show password',
    'to_confirm' => 'To confirm, type ":phrase"',
    'to_confirm_named' => 'To confirm, type the :label ":phrase"',
];
