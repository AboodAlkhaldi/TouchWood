<?php

declare(strict_types=1);

// The Reports page (ops.md §4.15 O8). Wording approved for now; the owner checks the whole site's wording later.
// A report's own words - its title, sheets, columns and notes - are ops::reports, in the reader's account language.
return [
    'title' => 'التقارير',
    'subtitle' => 'اختر التقرير والمتجر والأيام، ثم نزّله ملف Excel.',
    'kinds_label' => 'التقارير',
    'kinds' => [
        'revenue' => 'الإيرادات',
        'product-sales' => 'مبيعات المنتجات',
        'stock' => 'المخزون',
        'searches' => 'عمليات البحث',
        'favourites' => 'المفضلة',
    ],
    'choices_label' => 'خيارات التقرير',
    'days' => 'الأيام',
    'any_date' => 'اختر الأيام',
    'preset_today' => 'اليوم',
    'preset_week' => 'آخر 7 أيام',
    'preset_month' => 'آخر 30 يومًا',
    'preset_month_to_date' => 'الشهر حتى اليوم',
    'days_helper' => 'تُحسب الأيام بتوقيت كل متجر، لا بتوقيت UTC.',
    'as_now' => 'يعرض هذا التقرير الحال كما هو الآن، فلا أيام لاختيارها.',
    'by' => 'التجميع',
    'by_day' => 'حسب اليوم',
    'by_month' => 'حسب الشهر',
    'show' => 'عرض التقرير',
    'download' => 'تنزيل Excel',
    'before' => 'اختر المتجر والأيام، ثم اضغط عرض التقرير.',
    'before_now' => 'اختر المتجر، ثم اضغط عرض التقرير.',
    'changed' => 'تغيّرت الخيارات. اضغط عرض التقرير مرة أخرى.',
    'language' => [
        'ar' => 'هذا التقرير بالعربية، لغة حسابك.',
        'en' => 'هذا التقرير بالإنجليزية، لغة حسابك.',
    ],
    'sheets_label' => 'الأوراق',
    'empty' => 'لا شيء في هذه الأيام.',
    'more' => 'تظهر أول 50 صفًا. كل الصفوف في ملف Excel.',
];
