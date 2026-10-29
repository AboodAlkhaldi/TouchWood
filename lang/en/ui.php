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
    'breadcrumbs_more' => 'Earlier Steps',
    // The phone's sidebar, which shadcn names in English (frontend.md §1.11, edit 5).
    'sidebar' => 'Sidebar',
    'sidebar_description' => 'Displays the mobile sidebar.',
    'show_password' => 'Show password',
    'to_confirm' => 'To confirm, type ":phrase"',
    'to_confirm_named' => 'To confirm, type the :label ":phrase"',
    // A side panel's own close button (shadcn's Sheet draws an English one; ours is given words).
    'close' => 'Close',
    // A box typed with a value other than the one saved (pricing.md §4.3.1, "Rows that hold boxes").
    'changed' => 'Changed',
    // Leaving a page with changed boxes asks first (§4.3.1); the page says what would be lost.
    'leave' => [
        'title' => 'Leave Without Saving',
        'confirm' => 'Leave Page',
        'stay' => 'Stay',
        // Several sets of boxes with changes - a product's store cards (stage 5 step 10): how many places.
        'places' => [
            'zero' => 'You have unsaved changes in :count places.',
            'one' => 'You have unsaved changes in :count place.',
            'two' => 'You have unsaved changes in :count places.',
            'few' => 'You have unsaved changes in :count places.',
            'many' => 'You have unsaved changes in :count places.',
            'other' => 'You have unsaved changes in :count places.',
        ],
    ],
    // A moment typed in the store's clock (pricing.md §4.3.1 "Moments", Q4; components/DateTimeField).
    'moment' => [
        'now' => 'Now',
        'no_end' => 'No End',
        'date' => 'Date',
        'time' => 'Time',
        'pick_date' => 'Pick a Date',
        'passed' => 'Starts when saved if this time has passed.',
    ],
    // What a box says is wrong as it is typed (frontend.md §1.7; resources/js/lib/checks.ts), in
    // Geist's "Validation" form: the box, then its rule, a period. :field is the box's label.
    'check' => [
        'required' => ':field is required.',
        'number' => ':field takes numbers only.',
        'whole' => ':field takes whole numbers only.',
        'decimals_one' => ':field takes at most :count decimal place.',
        'decimals' => ':field takes at most :count decimal places.',
        'range' => ':field is from :min to :max.',
        'at_least' => ':field is at least :min.',
        'at_most' => ':field is at most :max.',
        'digits' => ':field takes the digits 0 to 9 only.',
        'min_digits' => ':field needs at least :min digits.',
        'max_digits' => ':field is at most :max digits.',
        'letters' => ':field takes the letters A to Z only.',
        'min_length' => ':field needs at least :min characters.',
        'max_length' => ':field is at most :max characters.',
        'max_length_one' => ':field is at most one character.',
        'email' => ':field takes an address like name@example.com.',
        'phone' => ':field takes a number with its country code, starting with + or 00.',
        // A moment's own rules (resources/js/lib/moments.ts).
        'date' => ':field needs a date.',
        'time' => ':field takes a time like 14:30.',
        'not_past' => ':field must be now or later.',
        'after_start' => ':field must be after the start.',
    ],
];
