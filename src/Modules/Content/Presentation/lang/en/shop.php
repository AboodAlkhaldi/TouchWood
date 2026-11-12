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
        'products' => 'Products Used',
        'back' => 'Projects',
    ],
    'campaign' => [
        'ends' => 'Ends :date',
        'empty' => 'No products to show right now.',
    ],
];
