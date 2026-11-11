<?php

declare(strict_types=1);

// W10 · SEO (content.md §4.4, §1.14). Wording approved for now; the owner checks the whole site's wording later.
return [
    'title' => 'SEO',
    'subtitle' => 'How this store\'s pages show in search results and when shared.',
    'defaults' => 'Defaults',
    'defaults_helper' => 'Used on every page whose own fields are left blank. Shown at once, so each takes both languages.',
    'site_name_ar' => 'Site Name (Arabic)',
    'site_name_en' => 'Site Name (English)',
    'site_name_rule' => '60 characters at most.',
    'title_pattern_ar' => 'Title Pattern (Arabic)',
    'title_pattern_en' => 'Title Pattern (English)',
    'title_pattern_rule' => ':title is the page\'s own title, :site the site name. 80 characters at most.',
    'sample' => 'Sample: :sample',
    'sample_title' => 'Office Chairs',
    'sample_title_ar' => 'كراسي المكتب',
    'description_ar' => 'Description (Arabic)',
    'description_en' => 'Description (English)',
    'description_rule' => '160 characters at most.',
    'share_image' => 'Share Image',
    'share_image_helper' => 'Shown when a page is shared and has no picture of its own.',
    'robots' => 'Robots Lines',
    'robots_helper' => 'Extra paths search engines skip, one a line, each a page of the shop without its store and language, such as /brands/old.',
    'save' => 'Save SEO',
    'saved' => 'SEO saved',
    'sitemap' => [
        'title' => 'Sitemap',
        'built' => 'Last built',
        'never' => 'Not built yet',
        'urls' => 'Addresses',
        'address' => 'Address',
        'copy' => 'Copy',
        'rebuild' => 'Rebuild Sitemap',
        'waits' => 'Queued',
        'waits_reason' => 'A rebuild is already queued.',
        'queued' => 'Sitemap rebuild queued',
        'console' => 'Google Search Console isn\'t changed by the system: add the sitemap there yourself.',
    ],
];
