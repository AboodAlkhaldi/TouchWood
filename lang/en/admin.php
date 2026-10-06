<?php

declare(strict_types=1);

// The admin shell's own words: the frame around every screen, belonging to no single module
// (frontend.md §2.2). A module's screens keep their words in the module's own lang files.
return [
    'panel' => 'Admin Panel',
    // The sidebar's own controls, read aloud in the page's language (shadcn writes them in English).
    'sidebar_toggle' => 'Toggle Sidebar',
    // Said after a menu entry's name, for a screen reader on the rail of icons (owner's #2, 2026-10-02);
    // the pause before it is the language's own comma.
    'menu_waiting' => ', :count waiting',
    'open_menu' => 'Open the menu',
    'close_menu' => 'Close the menu',
    'super_admin' => 'Super Admin',
    'coming_soon' => 'Soon',
    'coming_soon_title' => 'Coming Soon',
    'coming_soon_subtitle' => 'Not built yet.',
    'coming_soon_body' => 'This screen is next in the build queue.',

    'theme' => [
        // The switch's own name, read aloud (Geist's Switch carries an aria-label).
        'label' => 'Theme',
        'system' => 'System',
        'light' => 'Light',
        'dark' => 'Dark',
    ],

    'store' => [
        'label' => 'Store',
        'stores' => 'Stores',
        'fell_back' => 'You no longer have access to that store; showing :store',
        'fell_back_off' => 'The store you were working in is switched off; showing :store',
        // The switcher's mark on an off store, and why staff cannot choose it (access.md amendment 58(a)).
        'off' => 'Off',
        'off_reason' => ':store is switched off.',
        'changed' => 'Store changed to :store',
    ],

    'home' => [
        'title' => 'Home',
        'subtitle' => 'The admin panel.',
        'empty' => 'What you may open is in the menu.',
        'empty_title' => 'Nothing Here Yet',
        // Above the rows of what waits (frontend.md E7; owner, 2026-10-03): Geist's Note, a label
        // and one sentence; each row then opens its screen and shows its count.
        'waiting_label' => 'Waiting',
        'waiting_note' => 'Some things need you.',
        // The cards' scope (frontend.md §2.2; the owner's fix list, point 6).
        'scope' => 'Scope',
        'scope_all' => 'All Stores',
        'scope_store' => 'This Store',
    ],

    // The person block at the foot of the sidebar (frontend.md §3.1): their own account, and the
    // way out. It belongs to the frame rather than to any one module's screens.
    'account_settings' => 'Account & Settings',
    // The person menu's way out, carried by every panel page with the rest of this file.
    'sign_out' => 'Sign Out',

    // Shared components may read the frame's words, because every admin page ships this file.
    'close' => 'Close',
];
