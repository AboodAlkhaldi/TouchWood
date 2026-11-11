<?php

declare(strict_types=1);

// W9 · Projects (content.md §4.4, §1.13). Wording approved for now; the owner checks the whole site's wording later.
return [
    'title' => 'Projects',
    'subtitle' => 'Finished client projects, with their story, photos and the products used.',
    'new' => 'New Project',
    'empty' => 'No projects yet.',
    'empty_body' => 'A project is published at once, with its photos.',
    'hidden' => 'The Projects page stays hidden until the first project is published.',
    'item' => 'project',
    'publish' => 'Publish Project',
    'fields' => [
        'city_ar' => 'City (Arabic)',
        'city_en' => 'City (English)',
        'city_rule' => 'Optional, 100 characters at most.',
        'photos' => 'Photos',
        'photos_helper' => 'From 1 to 20, dragged into order. The first is the project\'s picture.',
        'add_photo' => 'Add Photo',
        'photos_full' => 'At most 20 photos.',
        'no_photos' => 'No photos yet.',
        'photo' => 'Photo :number',
    ],
    'unpublish' => [
        'menu' => 'Unpublish Project',
        'title' => 'Unpublish this project?',
        'body' => 'Shoppers can no longer open it. It stays here as a draft. With none published, the Projects page is hidden again.',
        'confirm' => 'Unpublish Project',
    ],
    'delete' => [
        'menu' => 'Delete Project',
        'title' => 'Delete this project?',
        'body' => 'The draft is deleted for good, and its addresses are free again.',
        'confirm' => 'Delete Project',
    ],
];
