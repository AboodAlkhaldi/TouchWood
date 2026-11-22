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
        'client_name_ar' => 'Client name (Arabic)',
        'client_name_en' => 'Client name (English)',
        'client_name_rule' => 'Optional, 100 characters at most. Shown as "With" and the name.',
        'client_logo' => 'Client logo',
        'client_logo_helper' => 'Shown in the client box on the project\'s page.',
        'client_link' => 'Client link',
        'client_link_helper' => 'Optional. A full address starting with https://.',
        'photos' => 'Photos',
        'photos_helper' => 'From 1 to 20, dragged into order. The first is the project\'s picture.',
        'add_photo' => 'Add Photo',
        'photos_full' => 'At most 20 photos.',
        'no_photos' => 'No photos yet.',
        'photo' => 'Photo :number',
        // flags-2026-11.md S4.4: YouTube videos, after the photos in the shop.
        'videos' => 'Videos',
        'videos_helper' => 'Shown in the shop after the photos, in this order. At most 10.',
        'video_link' => 'YouTube Link',
        'video_link_helper' => 'A link from YouTube: youtube.com/watch?v=…, youtu.be/…, /shorts/… or /embed/….',
        'video_paste' => 'Paste a YouTube link.',
        'video_once' => 'This video is already in the list.',
        'add_video' => 'Add Video',
        'video' => 'Video :number',
        'video_title_ar' => 'Arabic Title',
        'video_title_en' => 'English Title',
        'videos_full' => 'At most 10 videos.',
        'no_videos' => 'No videos yet.',
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
