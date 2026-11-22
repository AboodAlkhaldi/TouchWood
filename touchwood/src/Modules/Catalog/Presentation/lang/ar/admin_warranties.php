<?php

declare(strict_types=1);

// The warranties screen (catalog.md §1.9, §4.4 S6). Formal Arabic (frontend.md §1.10).
return [
    'title' => 'الضمانات',
    'subtitle' => 'الضمانات التي قد يحملها المنتج، واحد على الأكثر، وهو نفسه في كل المتاجر.',
    'add' => 'إضافة ضمان',
    'body' => 'اسمه ومدته وشروطه، باللغتين.',
    'edit' => 'تعديل…',
    'edit_title' => 'تعديل الضمان',
    'save' => 'حفظ الضمان',
    'activate' => 'تفعيل الضمان',
    'deactivate' => 'تعطيل الضمان',
    'delete' => 'حذف الضمان…',
    'delete_title' => 'حذف الضمان',
    'delete_body' => 'يُحذف :name.',
    'lifetime' => 'مدى الحياة',
    'months' => [
        'zero' => ':count شهر',
        'one' => 'شهر واحد',
        'two' => 'شهران',
        'few' => ':count أشهر',
        'many' => ':count شهرًا',
        'other' => ':count شهر',
    ],
    'empty' => [
        'title' => 'لا توجد ضمانات بعد',
        'body' => 'أضف الضمانات التي قد تحملها المنتجات.',
    ],
    'column' => [
        'period' => 'المدة',
    ],
    'field' => [
        'period' => 'الأشهر',
        'period_helper' => 'من 1 إلى 600.',
        'terms_ar' => 'الشروط بالعربية',
        'terms_en' => 'الشروط بالإنجليزية',
    ],
    'reason' => [
        'in_use' => 'تحمله منتجات: أعطها ضمانًا آخر أولًا.',
    ],
    'toast' => [
        'added' => 'أُضيف الضمان',
        'edited' => 'حُفظ الضمان',
        'activated' => 'فُعّل الضمان',
        'deactivated' => 'عُطّل الضمان',
        'deleted' => 'حُذف الضمان',
    ],
];
