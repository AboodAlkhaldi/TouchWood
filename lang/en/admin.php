<?php

declare(strict_types=1);

// The admin shell's own words: the frame around every screen, belonging to no single module
// (frontend.md §2.2). A module's screens keep their words in the module's own lang files.
return [
    'panel' => 'Admin panel',
    'open_menu' => 'Open the menu',
    'close_menu' => 'Close the menu',
    'super_admin' => 'Super Admin',
    'coming_soon' => 'Soon',
    'coming_soon_subtitle' => 'Not built yet.',
    'coming_soon_body' => 'This screen is next in the build queue.',

    'theme' => [
        'light' => 'Light',
        'dark' => 'Dark',
        'switch_to_light' => 'Switch to the light theme',
        'switch_to_dark' => 'Switch to the dark theme',
    ],

    'store' => [
        'label' => 'Store',
        'fell_back' => 'You no longer have access to that store. Showing :store.',
        'changed' => 'Now working in :store.',
    ],

    'home' => [
        'title' => 'Home',
        'subtitle' => 'The admin panel.',
        'empty' => 'The screens arrive with their modules. What you may open is in the menu.',
    ],
];
