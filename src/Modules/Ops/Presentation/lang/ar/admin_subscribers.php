<?php

declare(strict_types=1);

// The Subscribers page (ops.md §4.15 O7). Wording approved for now; the owner checks the whole site's wording later.
return [
    'title' => 'المشتركون',
    'subtitle' => 'من تصلهم النشرة البريدية. تُكتب النشرات وتُرسل من Brevo.',
    'empty' => 'لا يوجد مشتركون بعد.',
    'columns' => [
        'email' => 'البريد الإلكتروني',
        'language' => 'اللغة',
        'kind' => 'النوع',
        'state' => 'الحالة',
        'joined' => 'تاريخ الاشتراك',
        'confirmed' => 'تاريخ التأكيد',
    ],
    'language' => [
        'ar' => 'العربية',
        'en' => 'الإنجليزية',
    ],
    'kind' => [
        'GUEST' => 'زائر',
        'CUSTOMER' => 'عميل',
    ],
    'state' => [
        'confirmed' => 'مؤكَّد',
        'waiting' => 'بانتظار التأكيد',
    ],
    'hidden' => 'مخفي',
    'hidden_why' => 'لا يمكنك عرض عملاء هذا المتجر.',
    'remove' => [
        'menu' => 'إزالة',
        'title' => 'إزالة :email من النشرة البريدية؟',
        'body' => 'لن تصله النشرة البريدية بعد ذلك.',
        'keep' => 'إبقاء',
        'confirm' => 'إزالة',
        'toast' => 'أُزيل من النشرة البريدية.',
    ],
];
