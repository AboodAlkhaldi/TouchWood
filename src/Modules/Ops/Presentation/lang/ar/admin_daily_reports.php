<?php

declare(strict_types=1);

// The Daily Reports page (ops.md §4.15 O9). Wording approved for now; the owner checks the whole site's wording later.
return [
    'title' => 'التقارير اليومية',
    'subtitle' => 'أرقام يوم واحد في ملف PDF، تُنشأ عند الطلب أو تصلك بالبريد كل يوم.',
    'no_store' => 'لا يوجد متجر للاختيار. يمكن لمدير عام تفعيل متجرك.',
    'make' => [
        'title' => 'إنشاء تقرير',
        'store' => 'المتجر',
        'day' => 'أي يوم',
        'today' => 'اليوم حتى الآن',
        'past' => 'يوم سابق',
        'date' => 'التاريخ',
        'button' => 'إنشاء التقرير',
        'busy' => 'جارٍ إنشاء التقرير…',
        'toast' => 'التقرير جاهز.',
    ],
    'email' => [
        'title' => 'البريد اليومي',
        'on' => 'إرسال البريد اليومي لـ :store',
        'time' => 'وقت :store',
        'rule' => 'بنظام 24 ساعة، من 00:01 إلى 23:59.',
        'next' => 'التالي: :time',
        'saved' => 'تم الحفظ',
        'failed' => 'تعذر الحفظ. حاول مرة أخرى.',
    ],
    'list' => [
        'title' => 'التقارير المنشأة',
        'empty' => 'لا توجد تقارير بعد.',
        'past_day' => 'يوم سابق',
        'none' => 'لم يُرسل بالبريد',
        'download' => 'تنزيل PDF',
        'columns' => [
            'store' => 'المتجر',
            'day' => 'التاريخ',
            'made_by' => 'أنشأه',
            'made' => 'وقت الإنشاء',
            'language' => 'اللغة',
            'size' => 'الحجم',
            'emailed' => 'أُرسل بالبريد',
            'file' => 'الملف',
        ],
    ],
    'language' => [
        'ar' => 'العربية',
        'en' => 'الإنجليزية',
    ],
];
