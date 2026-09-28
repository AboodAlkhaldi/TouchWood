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
    'flagged_item_not_replaced' => [
        'title' => 'استبدل العناصر المحدّدة',
        'detail' => 'استبدل كل عنصر حُدّد في القرار الأخير قبل إرسال الطلب.',
    ],
    'request_not_answered' => [
        'title' => 'مطلوب بلا إجابة',
        'detail' => 'أجب عن كل ما طُلب في القرار الأخير قبل إرسال الطلب.',
    ],
    'document_no_longer_accepted' => [
        'title' => 'مستند لم يعد مقبولًا',
        'detail' => 'احذف كل مستند عليه علامة «لم يعد مقبولًا» قبل إرسال الطلب.',
    ],
    'request_not_found' => [
        'title' => 'المطلوب غير موجود',
        'detail' => 'هذا المطلوب ليس من القرار الأخير.',
    ],
    'answer_kind_mismatch' => [
        'title' => 'أجب كما طُلب',
        'detail' => 'أجب عن هذا المطلوب بالطريقة التي يطلبها: نصًّا أو ملفًّا.',
    ],
    'not_a_company_account' => [
        'title' => 'لحسابات الشركات فقط',
        'detail' => 'لا يتقدّم بطلب شركة إلا حساب شركة.',
    ],
    'company_not_found' => [
        'title' => 'لا شركة بعد',
        'detail' => 'لا شركة لهذا الحساب حتى يُرسَل أول طلب له.',
    ],
    'application_not_found' => [
        'title' => 'لا طلب',
        'detail' => 'لا يوجد طلب لتعديله. ابدأ طلبًا أولًا.',
    ],
    'application_already_open' => [
        'title' => 'بانتظار القرار',
        'detail' => 'طلبك بانتظار القرار. يمكنك أن تبدأ طلبًا آخر بعد البتّ فيه.',
    ],
    'email_not_verified' => [
        'title' => 'أكّد بريدك الإلكتروني',
        'detail' => 'أكّد عنوان بريدك الإلكتروني قبل إرسال الطلب.',
    ],
    'document_type_inactive' => [
        'title' => 'مستند غير مقبول',
        'detail' => 'هذا النوع من المستندات غير مقبول. اختر نوعًا من القائمة.',
    ],
    'application_file_not_found' => [
        'title' => 'الملف غير موجود',
        'detail' => 'هذا الملف ليس من ملفات طلباتك.',
    ],
    'fields' => [
        'address' => 'العنوان',
        'answer' => 'الإجابة',
        'company_type' => 'نوع الشركة',
        'company_type_other' => 'نوع الشركة',
        'cr_number' => 'رقم السجل التجاري',
        'flags' => 'العناصر المحدّدة',
        'label' => 'الوصف',
        'name' => 'اسم الشركة',
        'name_ar' => 'الاسم بالعربية',
        'name_en' => 'الاسم بالإنجليزية',
        'note' => 'الملاحظة',
        'position' => 'الترتيب',
        'reason' => 'السبب',
        'tax_number' => 'الرقم الضريبي',
    ],
];
