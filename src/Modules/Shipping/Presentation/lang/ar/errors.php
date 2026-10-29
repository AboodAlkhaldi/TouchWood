<?php

declare(strict_types=1);

// رسائل أخطاء الشحن حسب نوع الخطأ (shipping.{key})، وأسماء الحقول التي تشير إليها (shipping.md §7).
return [
    'carrier_not_found' => [
        'title' => 'شركة الشحن غير موجودة',
        'detail' => 'لم يُعثر على شركة الشحن هذه في هذا المتجر.',
    ],
    'carrier_still_on' => [
        'title' => 'شركة الشحن ما زالت مفعّلة',
        'detail' => 'أوقف ":name_ar" قبل حذفها.',
    ],
    'country_not_shipped' => [
        'title' => 'دولة لا يشحن إليها المتجر',
        'detail' => 'لا يشحن هذا المتجر إلى ":country". يضيفها مدير النظام إلى دول المتجر أولًا.',
    ],
    'country_has_carriers' => [
        'title' => 'للدولة شركات شحن',
        'detail' => 'ما زال للمتجر :carriers من شركات الشحن في ":country". أوقفها واحذفها أولًا.',
    ],
    'carrier_name_taken' => [
        'title' => 'الاسم مستخدم',
        'detail' => 'لشركة شحن أخرى في هذا المتجر الاسم ":name_ar". اختر اسمًا آخر.',
    ],
    'invalid_shipping_input' => [
        'title' => 'تحقّق من البيانات',
        'detail' => 'قيمة :field غير صحيحة. تحقّق منها وحاول مرة أخرى.',
    ],
    'fields' => [
        'store' => 'المتجر',
        'name_ar' => 'الاسم بالعربية',
        'name_en' => 'الاسم بالإنجليزية',
        'logo_media_id' => 'الشعار',
        'phone' => 'الهاتف',
        'website' => 'الموقع الإلكتروني',
        'tracking_pattern' => 'رابط التتبع',
        'carriers' => 'ترتيب شركات الشحن',
    ],
];
