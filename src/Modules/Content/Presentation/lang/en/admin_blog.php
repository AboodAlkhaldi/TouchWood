<?php

declare(strict_types=1);

// W8 · Blog (content.md §4.4, §1.12): posts and categories. Wording approved for now; the owner checks the whole site's
// wording later.
return [
    'title' => 'Blog',
    'subtitle' => 'Posts with their categories, cover and linked products. No comments.',
    'tabs' => [
        'posts' => 'Posts',
        'categories' => 'Categories',
    ],
    'new' => 'New Post',
    'empty' => 'No posts yet.',
    'empty_body' => 'A post is published at once, with its date.',
    'filter' => 'Category',
    'all_categories' => 'All Categories',
    'no_category' => '—',
    'item' => 'post',
    'publish' => 'Publish Post',
    'fields' => [
        'summary_ar' => 'Summary (Arabic)',
        'summary_en' => 'Summary (English)',
        'summary_rule' => 'One line, 200 characters at most.',
        'cover' => 'Cover Image',
        'cover_helper' => 'Required to publish.',
        'cover_alt_ar' => 'Cover Description (Arabic)',
        'cover_alt_en' => 'Cover Description (English)',
        'cover_alt_rule' => 'What the cover shows, 200 characters at most.',
        'category' => 'Category',
        'category_none' => 'No Category',
    ],
    'unpublish' => [
        'menu' => 'Unpublish Post',
        'title' => 'Unpublish this post?',
        'body' => 'Shoppers can no longer open it. It stays here as a draft.',
        'confirm' => 'Unpublish Post',
    ],
    'delete' => [
        'menu' => 'Delete Post',
        'title' => 'Delete this post?',
        'body' => 'The draft is deleted for good, and its addresses are free again.',
        'confirm' => 'Delete Post',
    ],
    'categories' => [
        'add' => 'Add Category',
        'edit' => 'Edit Category',
        'empty' => 'No categories yet.',
        'empty_body' => 'Posts can be filed under a category, in the order set here.',
        'posts' => ':count posts',
        'name_ar' => 'Name (Arabic)',
        'name_en' => 'Name (English)',
        'name_rule' => '80 characters at most.',
        'slug_ar' => 'Address (Arabic)',
        'slug_en' => 'Address (English)',
        'save' => 'Save Category',
        'saved' => 'Category saved',
        'deleted' => 'Category deleted',
        'menu' => [
            'edit' => 'Edit',
            'delete' => 'Delete Category',
        ],
        'holds_posts' => 'Move its posts first.',
        'delete' => [
            'title' => 'Delete this category?',
            'body' => 'It is deleted for good.',
            'confirm' => 'Delete Category',
        ],
    ],
];
