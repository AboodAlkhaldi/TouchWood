<?php

declare(strict_types=1);

/*
| The shop's own Content pages (content.md §4.5, H1-H7): the words around what staff type. Approved for now; the owner
| reads the whole site's wording later (reminder 16).
*/

return [
    'home' => [
        'banners' => 'العروض',
        'banner_of' => 'العرض :number من :count',
        'banner_previous' => 'العرض السابق',
        'banner_next' => 'العرض التالي',
        'all_projects' => 'كل المشاريع',
        'guides' => 'أدلة',
        'points_earn' => ':points نقطة لكل 1 :currency تنفقه',
        'points_off' => ':points نقطة تخصم 1 :currency',
    ],
    'blog' => [
        'title' => 'المدونة',
        'all' => 'الكل',
        'empty' => 'لا توجد مقالات بعد.',
        'categories' => 'التصنيفات',
    ],
    'projects' => [
        'title' => 'المشاريع',
    ],
    'post' => [
        'published' => 'نُشر في :date',
        'products' => 'منتجات في هذا المقال',
        'back' => 'المدونة',
    ],
    'project' => [
        'photos' => 'الصور',
        'products' => 'المنتجات المستخدمة',
        'back' => 'المشاريع',
    ],
    // صفحة السياسة (flags-2026-11.md S3.2، F15): آخر تغيير لنصها، ونسخة محفوظة برقمها.
    'policy' => [
        'updated' => 'آخر تحديث :date',
        'past' => 'هذه النسخة :number من هذه السياسة، نُشرت :date.',
        'current' => 'عرض النسخة الحالية',
    ],
    'campaign' => [
        'ends' => 'ينتهي :date',
        'empty' => 'لا توجد منتجات لعرضها الآن.',
    ],
];
