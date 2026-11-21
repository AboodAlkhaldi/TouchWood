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
        'with' => 'مع :client',
        'client_site' => 'موقع العميل',
        'client_box' => 'العميل',
        'photo_open' => 'فتح الصورة :number من :total',
        'viewer_title' => 'صور المشروع',
        'viewer_previous' => 'الصورة السابقة',
        'viewer_next' => 'الصورة التالية',
        'viewer_count' => ':number من :total',
        'products' => 'المنتجات المستخدمة',
        'back' => 'المشاريع',
    ],
    // صفحة السياسة (flags-2026-11.md S3.2، F15): آخر تغيير لنصها. النص الذي لم يعد ساريًا في المتجر - نسخة سابقة، أو سياسة
    // أُلغي نشرها فيه - يقول ذلك، مع تواريخ سريانه فيه (قرارات المالك في 2026-11-20).
    'policy' => [
        'updated' => 'آخر تحديث :date',
        'old' => 'هذه نسخة قديمة من :title، ولم تعد سارية.',
        'in_force' => 'كانت سارية من :from إلى :to.',
        'current' => 'اقرأ النسخة الحالية من :kind',
    ],
    'campaign' => [
        'ends' => 'ينتهي :date',
        'empty' => 'لا توجد منتجات لعرضها الآن.',
    ],
];
