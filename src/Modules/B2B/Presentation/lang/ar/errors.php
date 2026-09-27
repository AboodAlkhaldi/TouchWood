<?php

declare(strict_types=1);

// رسائل أخطاء B2B، حسب نوع الخطأ (b2b.{key}).
return [
    'invalid_company_attribute' => [
        'title' => 'تحقّق من بيانات الشركة',
        'detail' => 'قيمة :attribute غير صالحة.',
    ],
    'application_not_editable' => [
        'title' => 'أُرسل الطلب',
        'detail' => 'أُرسل هذا الطلب ولم يعد بالإمكان تعديله.',
    ],
    'missing_required_document' => [
        'title' => 'مستند ناقص',
        'detail' => 'أرفق كل المستندات المطلوبة قبل إرسال الطلب.',
    ],
    'company_type_inactive' => [
        'title' => 'اختر نوع الشركة من جديد',
        'detail' => 'نوع الشركة الذي اخترته لم يعد متاحًا. اختر نوعًا آخر، أو «أخرى».',
    ],
    'company_suspended' => [
        'title' => 'الشركة معلّقة',
        'detail' => 'لا يمكن تعديل البيانات المسجّلة للشركة ما دامت معلّقة.',
    ],
    'invalid_company_status' => [
        'title' => 'لا شيء لتغييره',
        'detail' => 'حالة الشركة لا تسمح بهذا التغيير.',
    ],
    'fields' => [
        'address' => 'العنوان',
        'company_type' => 'نوع الشركة',
        'company_type_other' => 'نوع الشركة',
        'cr_number' => 'رقم السجل التجاري',
        'name' => 'اسم الشركة',
        'name_ar' => 'الاسم بالعربية',
        'name_en' => 'الاسم بالإنجليزية',
        'note' => 'الملاحظة',
        'position' => 'الترتيب',
        'reason' => 'السبب',
        'tax_number' => 'الرقم الضريبي',
    ],
];
