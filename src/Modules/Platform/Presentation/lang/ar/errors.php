<?php

declare(strict_types=1);

return [
    'store_not_found' => [
        'title' => 'المتجر غير موجود',
        'detail' => 'لا يوجد متجر بالرمز ":code".',
    ],
    'store_code_taken' => [
        'title' => 'رمز المتجر مستخدم',
        'detail' => 'الرمز ":code" مستخدم لمتجر آخر.',
    ],
    'store_attribute_immutable' => [
        'title' => 'لا يمكن التعديل',
        'detail' => 'لا يمكن تعديل :attribute للمتجر بعد إنشائه.',
    ],
    'invalid_store_attribute' => [
        'title' => 'بيانات المتجر غير صالحة',
        'detail' => 'قيمة :attribute للمتجر غير صالحة.',
    ],
    'invalid_tax_rate' => [
        'title' => 'نسبة الضريبة غير صالحة',
        'detail' => 'يجب أن تكون نسبة الضريبة بين 0% و100%.',
    ],
    'invalid_timezone' => [
        'title' => 'المنطقة الزمنية غير صالحة',
        'detail' => '":timezone" ليست منطقة زمنية صالحة.',
    ],
    'currency_not_found' => [
        'title' => 'العملة غير موجودة',
        'detail' => 'لا توجد عملة بالرمز ":code".',
    ],
    'currency_already_exists' => [
        'title' => 'العملة موجودة مسبقًا',
        'detail' => 'العملة ":code" موجودة مسبقًا.',
    ],
    'currency_exponent_locked' => [
        'title' => 'لا يمكن تغيير الخانات العشرية',
        'detail' => 'لا يمكن تغيير الخانات العشرية للعملة ":code" لأن أحد المتاجر يستخدمها.',
    ],
    'invalid_currency_attribute' => [
        'title' => 'بيانات العملة غير صالحة',
        'detail' => 'قيمة :attribute للعملة غير صالحة.',
    ],
    'unknown_setting' => [
        'title' => 'إعداد غير معروف',
        'detail' => 'لا يوجد إعداد باسم ":key".',
    ],
    'invalid_setting_value' => [
        'title' => 'قيمة الإعداد غير صالحة',
        'detail' => 'القيمة المدخلة للإعداد ":key" غير صالحة.',
    ],
    'setting_scope_mismatch' => [
        'title' => 'نطاق الإعداد غير صحيح',
        'detail' => 'لا يمكن استخدام الإعداد ":key" بهذه الطريقة: تحقق هل هو خاص بمتجر أم لكل المتاجر.',
    ],
    'missing_translation' => [
        'title' => 'الترجمة ناقصة',
        'detail' => 'يجب إدخال :attribute باللغتين العربية والإنجليزية.',
    ],
];
