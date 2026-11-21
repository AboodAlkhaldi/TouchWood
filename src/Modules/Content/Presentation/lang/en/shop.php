<?php

declare(strict_types=1);

/*
| The shop's own Content pages (content.md §4.5, H1-H7): the words around what staff type. Approved for now; the owner
| reads the whole site's wording later (reminder 16).
*/

return [
    'home' => [
        'banners' => 'Offers',
        'banner_of' => 'Offer :number of :count',
        'banner_previous' => 'Previous Offer',
        'banner_next' => 'Next Offer',
        'all_projects' => 'All Projects',
        'guides' => 'Guides',
        'points_earn' => ':points points for every 1 :currency you spend',
        'points_off' => ':points points take 1 :currency off',
    ],
    'blog' => [
        'title' => 'Blog',
        'all' => 'All',
        'empty' => 'No posts yet.',
        'categories' => 'Categories',
    ],
    'projects' => [
        'title' => 'Projects',
    ],
    'post' => [
        'published' => 'Published :date',
        'products' => 'Products in This Post',
        'back' => 'Blog',
    ],
    'project' => [
        'photos' => 'Photos',
        'with' => 'With :client',
        'client_site' => 'Client\'s site',
        'client_box' => 'Client',
        'photo_open' => 'Open photo :number of :total',
        'viewer_title' => 'Project photos',
        'viewer_previous' => 'Previous photo',
        'viewer_next' => 'Next photo',
        'viewer_count' => ':number of :total',
        'products' => 'Products Used',
        'back' => 'Projects',
    ],
    // A policy's page (flags-2026-11.md S3.2, F15): when its text last changed. A text no longer valid in the store - a
    // past version, or a policy switched off there - says so, with its dates there (the owner's decisions of 2026-11-20).
    'policy' => [
        'updated' => 'Last updated :date',
        'old' => 'This is an old version of the :title. It is no longer valid.',
        'in_force' => 'In force from :from to :to.',
        'current' => 'Read the current :kind',
    ],
    'campaign' => [
        'ends' => 'Ends :date',
        'empty' => 'No products to show right now.',
    ],
];
