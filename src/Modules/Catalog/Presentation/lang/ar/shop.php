<?php

declare(strict_types=1);

// The shop's pages (catalog.md §4.5): the menu and the search box in every page's frame, a
// category's, a brand's and a product's pages, the search results. No price, no stock, no code.
return [
    'frame' => [
        'all_categories' => 'جميع الأقسام',
        'categories' => 'الأقسام',
        'open_menu' => 'افتح الأقسام',
        'search' => 'بحث',
        'search_placeholder' => 'ابحث عن منتج…',
        'see_all' => 'عرض كل النتائج',
        'no_suggestions' => 'لا منتجات مطابقة بعد.',
        'all_of' => 'كل :name',
    ],
    'home' => 'الرئيسية',
    'last_pieces' => 'آخر القطع',
    'sub_categories' => 'الأقسام الفرعية',
    'products' => 'المنتجات',
    'filters' => [
        'title' => 'التصفية',
        'brands' => 'العلامات التجارية',
        'sides' => 'طريقة البيع',
        'retail' => 'تجزئة',
        'wholesale' => 'جملة',
        'clear_all' => 'مسح الكل',
        'remove' => 'إزالة :name',
        'none' => 'لا شيء هنا يطابق هذه التصفية.',
        'open' => 'عرض التصفية',
    ],
    'sort' => [
        'label' => 'الترتيب',
        'best-selling' => 'الأكثر مبيعًا',
        'newest' => 'الأحدث',
    ],
    'empty' => 'لا شيء معروض هنا بعد.',
    'product' => [
        'photo' => 'الصورة :number من :total',
        'details' => 'التفاصيل',
        'weight' => 'الوزن',
        'size' => 'الأبعاد',
        'grams' => ':value غ',
        'size_value' => ':length × :width × :height مم',
        'description' => 'الوصف',
        'warranty' => 'الضمان',
        'lifetime' => 'مدى الحياة',
        'months' => ':count شهرًا',
        'one_month' => 'شهر واحد',
        'how_sold' => 'طريقة البيع',
        'retail' => 'تجزئة',
        'wholesale' => 'جملة',
        'at_least' => 'لا يقل عن :count',
        'at_most' => 'ولا يزيد على :count',
        'goes_with' => 'يُستخدم معه',
        'may_also_like' => 'قد يعجبك أيضًا',
        'not_available' => 'غير متاح',
        'not_available_text' => 'لا يمكن طلب هذا المنتج هنا الآن.',
        'unavailable_value' => ':name، غير متاح',
    ],
    'search' => [
        'title' => 'البحث',
        'one' => 'نتيجة واحدة عن «:query»',
        'many' => ':count نتيجة عن «:query»',
        'none' => 'لا منتجات تطابق «:query».',
        'browse' => 'تصفّح الأقسام بدلًا من ذلك:',
        'short' => 'اكتب حرفين على الأقل للبحث.',
    ],
    'not_found' => [
        'title' => 'الصفحة غير موجودة',
        'back' => 'العودة إلى المتجر',
    ],
];
